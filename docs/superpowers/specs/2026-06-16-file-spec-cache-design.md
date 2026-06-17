# Persistent cache for file-backed specs (#7)

**Date:** 2026-06-16
**Issue:** #7 (persistent cache support for file-backed specs)
**Package state:** accord is at v1.2.0 (+#8 merged, unreleased). This is additive and backward compatible.

## Problem

`FileSpecSource` parses the OpenAPI file (YAML/JSON) on every `load()`. In PHP-FPM each
request is a fresh process, so the spec is re-parsed every request. YAML parsing
(symfony/yaml, pure PHP) is the dominant cost. `ContractValidator`'s in-process cache only
helps within a single process; nothing persists across requests.

## Validated assumption (benchmark)

`Reader::readFromJson(json_encode($spec->getSerializableData()))` reconstructs an equivalent
`OpenApi` (verified: same operation resolved). Timing on the `v1.yaml` fixture: YAML parse
**0.92 ms/op** vs JSON rehydrate **0.06 ms/op** → **~15×** faster. The gap widens for larger
specs (YAML parsing scales worse than native `json_decode`). So: cache the parsed spec as
JSON, rehydrate on a hit.

## Decisions (confirmed with user)

- **Cache format:** store `json_encode($spec->getSerializableData())`; rehydrate via
  `Reader::readFromJson`; a corrupt cache entry is treated as a miss (re-parse).
- **Invalidation:** `mtime` in the cache key → a redeployed/edited spec auto-invalidates
  (new key → miss → re-parse), no manual flush. TTL evicts stale-mtime entries.
- **Wiring:** one resolved cache feeds **both** `FileSpecSource` and `UrlSpecSource` (the
  Laravel provider currently passes only a TTL to `UrlSpecSource`, so URL caching is dead
  there today — this fixes it).
- **`spec_cache` config:** `null`/`false`/`''` = off, `true` = default store, string =
  named store.
- **External `$ref` caveat:** documented, not engineered around (YAGNI). A plan test
  confirms an **internal** `#/components` `$ref` spec round-trips through the cache.

## Architecture

The core (`src/` excluding `src/Drivers/`) stays framework-agnostic. `psr/simple-cache` is
already a dependency; `FileSpecSource` mirrors `UrlSpecSource`'s optional-PSR-16 shape.

### Components (dependency order, leaves → roots)

1. **`FileSpecSource` — optional PSR-16 cache** (core). New trailing ctor params:
   ```php
   public function __construct(
       private readonly string $basePath,
       private readonly string $pattern = '{base}/resources/openapi/{version}',
       private readonly ?CacheInterface $cache = null,
       private readonly int $ttl = 3600,
   ) {}
   ```
   `load($version)`:
   ```php
   $path = $this->findPath($version);
   if ($path === null) {
       return null;                       // unchanged: no spec → no constraint
   }
   if ($this->cache === null) {
       return $this->parse($path);        // unchanged behavior for non-cache callers
   }

   $cacheKey = sprintf('fissible.accord.spec.file.%s.%d', hash('xxh32', $path), @filemtime($path) ?: 0);

   try {
       $cached = $this->cache->get($cacheKey);
   } catch (\Throwable) {
       $cached = null;                    // cache get failure → treat as miss
   }

   if (is_string($cached)) {
       try {
           return Reader::readFromJson($cached);   // throws only on INVALID json; see note below
       } catch (\Throwable) {
           // unrehydratable cache entry → fall through to re-parse
       }
   }

   $spec = $this->parse($path);

   if ($spec !== null) {
       $json = json_encode($spec->getSerializableData());
       if (is_string($json)) {            // never set() a failed encode
           try {
               $this->cache->set($cacheKey, $json, $this->ttl);
           } catch (\Throwable) {
               // caching is best-effort; never break spec loading
           }
       }
   }

   return $spec;
   ```
   The existing parse logic is extracted into:
   ```php
   private function parse(string $path): ?OpenApi
   {
       return $this->isYaml($path)
           ? Reader::readFromYamlFile($path)
           : Reader::readFromJsonFile($path);
   }
   ```
   - **Distinct key namespace** `fissible.accord.spec.file.{xxh32(path)}.{mtime}` —
     `UrlSpecSource` stores *raw content* under `fissible.accord.spec.{xxh32(url)}`;
     `FileSpecSource` stores *serialized JSON*. The `.file.` segment prevents any
     format confusion if they ever share a store.
   - **Parse-error semantics unchanged:** a malformed local spec still throws (a deploy
     error worth surfacing). Only cache get/set/rehydrate are defensive — cache failures
     never break spec loading.
   - **Corrupt-cache scope (cebe caveat):** `Reader::readFromJson` only **throws** on
     *invalid* JSON (which the try/catch catches → re-parse). Valid-JSON-but-not-a-spec
     (e.g. `'{}'`) does **not** throw — cebe returns a (possibly empty) `OpenApi`. We do
     **not** add a shape guard for that, because the namespaced key
     (`fissible.accord.spec.file.{hash}.{mtime}`) only ever holds our own
     `getSerializableData()` writes; a foreign valid-JSON entry is not a real scenario.
     The guarantee is therefore: an *unrehydratable* (invalid-JSON) entry falls back to
     parsing — not "any non-spec content".
   - `findPath`, `isYaml`, `resolvedPath`, `exists` unchanged.

2. **Laravel config `accord.php`:**
   - Add `'spec_cache' => env('ACCORD_SPEC_CACHE', null)`, documented: `null`/`false`/`''` =
     off (in-process only, today's behavior), `true` = Laravel's default cache store, a
     string = a named store.
   - Update the existing `spec_cache_ttl` comment: it is **no longer URL-only** — it is the
     TTL backstop for both file and URL spec caches.

3. **`AccordServiceProvider`** — a `resolveSpecCache(): ?CacheInterface` helper, written to
   avoid the `true`→`store('1')` footgun:
   ```php
   private function resolveSpecCache(): ?CacheInterface
   {
       $store = config('accord.spec_cache');

       if ($store === null || $store === false || $store === '') {
           return null;
       }

       return $store === true
           ? $this->app->make('cache')->store()           // default store (NO argument)
           : $this->app->make('cache')->store($store);     // named store
   }
   ```
   (Laravel cache repositories implement `Psr\SimpleCache\CacheInterface`.) The
   `SpecSourceInterface` singleton resolves the cache once and passes it to **both** sources
   with `spec_cache_ttl`:
   ```php
   $cache = $this->resolveSpecCache();
   $ttl   = (int) config('accord.spec_cache_ttl', 3600);
   if ($type === 'url') {
       return new UrlSpecSource(pattern: $pattern, cache: $cache, ttl: $ttl);
   }
   return new FileSpecSource(base_path(), $pattern, $cache, $ttl);
   ```

4. **`AccordFactory`** (Slim/Mezzio) — `makeSpecSource` already passes
   `$config['spec_cache']` (a PSR-16 instance) to `UrlSpecSource`; add the same instance +
   `(int) ($config['spec_cache_ttl'] ?? 3600)` to the `FileSpecSource` branch.

5. **README** — a "Caching the spec" subsection: when to enable (PHP-FPM re-parses the spec
   per request), the `spec_cache` config shape, mtime invalidation (no flush on deploy), and
   two caveats:
   - **Long-lived workers (Octane/RoadRunner):** `ContractValidator` keeps an in-process
     parsed spec per version for the life of the worker, so mtime invalidation only helps
     fresh validator instances (PHP-FPM, new processes). Long-lived workers keep the old
     parsed spec until the worker restarts — restart workers on deploy (which these stacks
     already do).
   - **External `$ref`s:** the cache stores the spec's serialized data; specs relying on
     **external-file** `$ref`s may not round-trip — keep specs self-contained. Internal
     `#/components` refs are fine.

## Error handling / backward compatibility

- `cache` defaults to `null` everywhere → identical behavior for anyone not opting in.
- New `FileSpecSource` ctor params are optional and trailing; `SpecSourceInterface` unchanged.
- Cache get/set failures and corrupt/unencodable entries fall back to parsing; malformed
  local specs still throw as before.

## Testing (TDD, one behavior per test)

Test double: a small in-memory PSR-16 cache — `tests/Support/ArrayCache.php` implementing
`Psr\SimpleCache\CacheInterface` over a public `$store` array (all 8 methods; the unused
multi/has methods are trivial).

`FileSpecSource` (unit, `tests/Unit/FileSpecSourceTest.php`):
- No cache (null) → loads/parses as before (existing tests already cover this; keep green).
- Cache miss populates the cache: load with an `ArrayCache` → `$store` has exactly one entry
  whose value is JSON that rehydrates (via `Reader::readFromJson`) to a spec exposing the
  expected operation.
- Cache hit is used: load once (populates), then overwrite the single stored value with a
  *different* spec's JSON, load again → returned spec reflects the **cached** content (proves
  the hit path rehydrates from cache, not the file).
- Unrehydratable cache entry → overwrite the stored value with **invalid JSON**
  (`'{not valid openapi'`, which makes `Reader::readFromJson` throw) → load returns a valid
  spec by re-parsing the file (no throw). (Do not assert on valid-JSON-non-spec content —
  cebe does not throw on `'{}'`; see the corrupt-cache caveat above.)
- Cache failure resilience: an `ArrayCache` subclass whose `get`/`set` throw → load still
  returns the parsed spec.
- mtime invalidation (validate-against-reality): write a temp spec file, load (cache
  populated), modify its contents and bump mtime (`touch($file, time()+10)`), load again →
  the returned spec reflects the **new** file contents (new mtime ⇒ new key ⇒ re-parse).
- Internal `$ref` round-trips faithfully: a fixture spec using
  `$ref: '#/components/schemas/X'`. (Reality-checked: cebe keeps internal refs as
  `Reference` objects — it does NOT auto-resolve them, in both the cached and uncached
  paths.) Load-with-cache twice and assert
  `json_encode($first->getSerializableData()) === json_encode($second->getSerializableData())`
  — the cache-hit rehydration reproduces the file-parsed structure byte-for-byte, including
  the `$ref`. (Do not assert the ref resolves to a concrete schema — it doesn't, cached or
  not.)

Laravel provider / factory:
- Provider (file): bind a fake `cache` manager whose `store()` returns an `ArrayCache`; set
  `accord.spec_cache => true`, `accord.spec_source => file`; resolve `SpecSourceInterface`;
  load a fixture version → the `ArrayCache` gains an entry (proves provider wiring +
  `true` → default store, not `store('1')`).
- Provider (url — proves the dead URL-cache wiring is fixed): bind a fake `cache` manager
  whose `store()` returns an `ArrayCache`; set `accord.spec_cache => true`,
  `accord.spec_source => url`, and `accord.spec_pattern => 'file://' . <abs fixtures dir> .
  '/{version}.yaml'` (a `file://` URL is fetchable by `UrlSpecSource`'s `file_get_contents`);
  resolve `SpecSourceInterface`; `load('v1')` → the `ArrayCache` gains an entry (proves the
  resolved cache is now passed to `UrlSpecSource`, which previously received only a TTL).
- `AccordFactory`: pass `spec_cache` (an `ArrayCache` instance) + `spec_source => file`;
  build the middleware; process a `/v1/...` request that loads the spec → the `ArrayCache`
  is populated (proves factory file-cache wiring).

Whole suite stays green (currently 137) with no new deprecations.

## Out of scope (YAGNI)

- Changing `ContractValidator`'s in-process cache (worker staleness is documented, not fixed).
- Supporting external-file `$ref`s through the cache.
- A CLI cache-warm/clear command (mtime keying + Laravel `cache:clear` suffice).
- Compiling specs to opcache-able PHP files (PSR-16 mirrors the existing pattern).
