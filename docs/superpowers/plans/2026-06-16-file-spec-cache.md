# Persistent File Spec Cache Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist the parsed OpenAPI spec across requests/processes via an optional PSR-16 cache on `FileSpecSource`, so PHP-FPM doesn't re-parse the YAML on every request.

**Architecture:** `FileSpecSource` gains an optional PSR-16 cache (mirroring `UrlSpecSource`). On a miss it parses the file and stores `json_encode($spec->getSerializableData())`; on a hit it rehydrates via `Reader::readFromJson` (~15× faster than YAML). The key includes the file's `mtime`, so a redeployed spec auto-invalidates. The Laravel provider and `AccordFactory` resolve a cache and wire it into both file and URL sources (fixing the dead URL-cache wiring in the provider).

**Tech Stack:** PHP 8.2+, PHPUnit 11, cebe/php-openapi, `psr/simple-cache` (already a dependency), illuminate/http (dev).

**Spec:** `docs/superpowers/specs/2026-06-16-file-spec-cache-design.md`

**Branch:** `feat/file-spec-cache` (already checked out).

**Conventions:** `declare(strict_types=1)` everywhere; no public non-readonly props; framework code only under `src/Drivers/`. Core classes are namespace `Fissible\Accord`. Run the suite with `vendor/bin/phpunit --colors=never`. **Baseline: 137 tests passing, 5 pre-existing cebe deprecations** — the count must stay 5.

---

## File Structure

**Tests (create):** `tests/Support/ArrayCache.php` (in-memory PSR-16 double), `tests/Fixtures/v3.yaml` (internal-`$ref` fixture).

**Core (modify):**
- `src/FileSpecSource.php` — optional `?CacheInterface $cache` + `int $ttl`; mtime-keyed JSON round-trip.
- `src/AccordFactory.php` — pass `spec_cache` + `spec_cache_ttl` to the `FileSpecSource` branch.

**Laravel driver (modify):**
- `src/Drivers/Laravel/config/accord.php` — `spec_cache` key; correct the `spec_cache_ttl` comment.
- `src/Drivers/Laravel/Providers/AccordServiceProvider.php` — `resolveSpecCache()` + wire into both sources.

**Tests (modify):** `tests/Unit/FileSpecSourceTest.php`, `tests/Feature/LaravelServiceProviderTest.php`, `tests/Unit/AccordFactoryTest.php`.

**Docs (modify):** `README.md`.

---

## Task 1: `ArrayCache` test double

**Files:**
- Create: `tests/Support/ArrayCache.php`

Test infrastructure (no behavior test). A minimal in-memory PSR-16 cache with a public `$store` so tests can inspect/overwrite entries. Declared as a plain `class` (not `final`) so a test can subclass it to simulate cache failures.

- [ ] **Step 1: Create the double**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal in-memory PSR-16 cache for tests. The public $store array makes the
 * cached entries inspectable/overwritable. Not for production use.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    /** @param iterable<string> $keys */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }
}
```

Note: `psr/simple-cache` is already a project dependency, so `CacheInterface` is available. `autoload-dev` maps `Fissible\Accord\Tests\` → `tests/`, so this resolves with no `composer dump-autoload`. `tests/Support` is not a PHPUnit suite, so it is not collected as a test.

- [ ] **Step 2: Commit**

```bash
git add tests/Support/ArrayCache.php
git commit -m "test: add in-memory ArrayCache PSR-16 double (#7)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `FileSpecSource` — optional PSR-16 cache

**Files:**
- Create: `tests/Fixtures/v3.yaml`
- Modify: `src/FileSpecSource.php`
- Test: `tests/Unit/FileSpecSourceTest.php`

The current `src/FileSpecSource.php` constructor is `(string $basePath, string $pattern = '{base}/resources/openapi/{version}')` and `load()` resolves the path then returns `Reader::readFromYamlFile($path)` / `readFromJsonFile($path)` directly. It has `exists()`, `resolvedPath()`, private `findPath()`, private `isYaml()`.

- [ ] **Step 1: Create the internal-`$ref` fixture**

Create `tests/Fixtures/v3.yaml`:

```yaml
openapi: '3.0.3'
info:
  title: Ref Spec
  version: '3'
paths:
  /v3/things:
    get:
      operationId: things.index
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Thing'
components:
  schemas:
    Thing:
      type: object
      required:
        - id
      properties:
        id:
          type: integer
```

- [ ] **Step 2: Write the failing tests**

Add `use Fissible\Accord\Tests\Support\ArrayCache;` to the imports of `tests/Unit/FileSpecSourceTest.php` (namespace `Fissible\Accord\Tests\Unit`; it already imports `FileSpecSource` and has `setUp()` setting `$this->fixturesPath = dirname(__DIR__) . '/Fixtures'`). Add these methods to the class:

```php
    public function test_cache_miss_populates_the_cache(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $spec = $source->load('v1');

        $this->assertNotNull($spec);
        $this->assertCount(1, $cache->store);

        $decoded = json_decode((string) array_values($cache->store)[0], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('openapi', $decoded);
        $this->assertArrayHasKey('paths', $decoded);
    }

    public function test_cache_hit_is_used_instead_of_the_file(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $source->load('v1'); // miss → populates the cache

        // Overwrite the single cached entry with a DIFFERENT spec.
        $key = array_key_first($cache->store);
        $cache->store[$key] = json_encode([
            'openapi' => '3.0.3',
            'info'    => ['title' => 'from cache', 'version' => '1'],
            'paths'   => ['/from/cache' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]],
        ]);

        $spec = $source->load('v1'); // hit → rehydrates the overwritten value

        $this->assertNotNull($spec);
        $this->assertTrue(isset($spec->paths['/from/cache']));
        $this->assertFalse(isset($spec->paths['/v1/users']));
    }

    public function test_invalid_cache_entry_falls_back_to_parsing(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $source->load('v1');
        $key = array_key_first($cache->store);
        $cache->store[$key] = '{not valid openapi'; // invalid JSON → readFromJson throws

        $spec = $source->load('v1'); // must re-parse the file, no throw

        $this->assertNotNull($spec);
        $this->assertTrue(isset($spec->paths['/v1/users']));
    }

    public function test_cache_failures_do_not_break_loading(): void
    {
        $cache = new class extends ArrayCache {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException('cache get failed');
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                throw new \RuntimeException('cache set failed');
            }
        };
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $spec = $source->load('v1');

        $this->assertNotNull($spec);
        $this->assertTrue(isset($spec->paths['/v1/users']));
    }

    public function test_mtime_change_invalidates_cache(): void
    {
        $dir  = sys_get_temp_dir() . '/accord_cache_' . uniqid();
        mkdir($dir);
        $file = $dir . '/v1.yaml';
        file_put_contents($file, "openapi: '3.0.3'\ninfo: { title: t, version: '1' }\npaths:\n  /first:\n    get:\n      responses:\n        '200': { description: OK }\n");

        $cache  = new ArrayCache();
        $source = new FileSpecSource($dir, '{base}/{version}', $cache);

        $first = $source->load('v1');
        $this->assertTrue(isset($first->paths['/first']));

        // Rewrite with different content and a NEW mtime; clear stat cache so filemtime re-reads.
        file_put_contents($file, "openapi: '3.0.3'\ninfo: { title: t, version: '1' }\npaths:\n  /second:\n    get:\n      responses:\n        '200': { description: OK }\n");
        touch($file, time() + 10);
        clearstatcache(true, $file);

        $second = $source->load('v1');
        $this->assertTrue(isset($second->paths['/second']));
        $this->assertFalse(isset($second->paths['/first']));

        unlink($file);
        rmdir($dir);
    }

    public function test_internal_ref_round_trips_through_cache(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $first  = $source->load('v3'); // miss → file parse
        $second = $source->load('v3'); // hit → rehydrate from cache

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(
            json_encode($first->getSerializableData()),
            json_encode($second->getSerializableData()),
        );
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter FileSpecSourceTest`
Expected: FAIL — `FileSpecSource::__construct()` does not accept a 3rd argument (`$cache`).

- [ ] **Step 4: Add caching to `FileSpecSource`**

Replace the entire contents of `src/FileSpecSource.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Psr\SimpleCache\CacheInterface;

/**
 * Loads OpenAPI specs from the local filesystem.
 *
 * The pattern uses {base} and {version} tokens and should NOT include a file
 * extension — FileSpecSource tries .yaml, .yml, and .json in that order.
 * If your pattern already includes an extension it is used as-is.
 *
 * Default pattern: {base}/resources/openapi/{version}
 *
 * Pass an optional PSR-16 cache to persist the PARSED spec across processes
 * (PHP-FPM): on a hit the spec is rehydrated from cached JSON, skipping the
 * (slow) YAML parse. The cache key includes the file's mtime, so a redeployed
 * spec auto-invalidates. In-process caching is handled by ContractValidator.
 */
class FileSpecSource implements SpecSourceInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $pattern = '{base}/resources/openapi/{version}',
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 3600,
    ) {}

    public function load(string $version): ?OpenApi
    {
        $path = $this->findPath($version);

        if ($path === null) {
            return null;
        }

        if ($this->cache === null) {
            return $this->parse($path);
        }

        $cacheKey = sprintf('fissible.accord.spec.file.%s.%d', hash('xxh32', $path), @filemtime($path) ?: 0);

        try {
            $cached = $this->cache->get($cacheKey);
        } catch (\Throwable) {
            $cached = null; // cache get failure → treat as miss
        }

        if (is_string($cached)) {
            try {
                return Reader::readFromJson($cached);
            } catch (\Throwable) {
                // unrehydratable (invalid-JSON) cache entry → fall through to re-parse
            }
        }

        $spec = $this->parse($path);
        $json = json_encode($spec->getSerializableData());

        if (is_string($json)) {
            try {
                $this->cache->set($cacheKey, $json, $this->ttl);
            } catch (\Throwable) {
                // caching is best-effort; never break spec loading
            }
        }

        return $spec;
    }

    public function exists(string $version): bool
    {
        return $this->findPath($version) !== null;
    }

    public function resolvedPath(string $version): ?string
    {
        return $this->findPath($version);
    }

    private function parse(string $path): OpenApi
    {
        return $this->isYaml($path)
            ? Reader::readFromYamlFile($path)
            : Reader::readFromJsonFile($path);
    }

    private function findPath(string $version): ?string
    {
        $resolved = str_replace(
            ['{base}', '{version}'],
            [$this->basePath, $version],
            $this->pattern,
        );

        if (file_exists($resolved)) {
            return $resolved;
        }

        foreach (['.yaml', '.yml', '.json'] as $ext) {
            if (file_exists($resolved . $ext)) {
                return $resolved . $ext;
            }
        }

        return null;
    }

    private function isYaml(string $path): bool
    {
        return str_ends_with($path, '.yaml') || str_ends_with($path, '.yml');
    }
}
```

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 137 prior + 6 new = 143 tests. Deprecations remain 5 (the v3 fixture triggers the same cebe deprecation messages, not new ones).

- [ ] **Step 6: Commit**

```bash
git add src/FileSpecSource.php tests/Unit/FileSpecSourceTest.php tests/Fixtures/v3.yaml
git commit -m "feat: optional PSR-16 cache for FileSpecSource (mtime-keyed JSON round-trip) (#7)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Laravel config — `spec_cache` + correct `spec_cache_ttl` doc

**Files:**
- Modify: `src/Drivers/Laravel/config/accord.php`

No isolated test (config data); exercised by Task 4.

- [ ] **Step 1: Add the `spec_cache` block**

In `src/Drivers/Laravel/config/accord.php`, insert this block immediately AFTER the `'spec_pattern' => env('ACCORD_SPEC_PATTERN', ...),` line and BEFORE the existing `Spec Cache TTL` comment block:

```php

    /*
    |--------------------------------------------------------------------------
    | Spec Cache
    |--------------------------------------------------------------------------
    | Optional persistent cache for the PARSED spec, across requests/processes
    | (PHP-FPM re-parses the spec on every request otherwise). Values:
    |   null | false | '' — off (in-process cache only; the default)
    |   true             — use the application's default cache store
    |   'store-name'     — use a named cache store (e.g. 'redis', 'file')
    | The file cache key includes the spec file's mtime, so a redeployed spec
    | auto-invalidates — no manual cache flush needed. Applies to file and url
    | sources. Long-lived workers (Octane/RoadRunner) keep an in-process parsed
    | spec until they restart, so restart workers on deploy.
    |
    */
    'spec_cache' => env('ACCORD_SPEC_CACHE', null),
```

- [ ] **Step 2: Correct the `spec_cache_ttl` comment**

Replace the body of the existing `Spec Cache TTL` comment block — find:

```php
    | Seconds to cache remotely fetched specs (url source only).
    | In standard PHP-FPM the in-process cache is sufficient; this is for
    | serverless or short-lived process environments.
```

and replace it with:

```php
    | Seconds a cached spec lives before this TTL backstop expires. Applies to
    | both file and url spec caches (when spec_cache is enabled). For files,
    | mtime keying already invalidates on change; the TTL just evicts stale
    | old-mtime entries so they don't accumulate.
```

- [ ] **Step 3: Verify syntax + suite**

Run: `php -l src/Drivers/Laravel/config/accord.php` → "No syntax errors detected".
Run: `vendor/bin/phpunit --colors=never` → 143 tests, 5 deprecations.

- [ ] **Step 4: Commit**

```bash
git add src/Drivers/Laravel/config/accord.php
git commit -m "feat: add spec_cache config knob; correct spec_cache_ttl doc (#7)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `AccordServiceProvider` — resolve + wire cache into both sources

**Files:**
- Modify: `src/Drivers/Laravel/Providers/AccordServiceProvider.php`
- Test: `tests/Feature/LaravelServiceProviderTest.php`

The current `SpecSourceInterface` singleton closure:

```php
        $this->app->singleton(SpecSourceInterface::class, function () {
            $type    = config('accord.spec_source', 'file');
            $pattern = config('accord.spec_pattern');

            if ($type === 'url') {
                return new UrlSpecSource(
                    pattern: $pattern,
                    ttl:     (int) config('accord.spec_cache_ttl', 3600),
                );
            }

            return new FileSpecSource(base_path(), $pattern);
        });
```

The test file `tests/Feature/LaravelServiceProviderTest.php` (namespace `Fissible\Accord\Tests\Feature`) has the `FakeLaravelContainer` (implements `CachesConfiguration`), `LaravelConfig` (static config store), `RecordingLogger`, `FakeLogManager`. Its `setUp()` sets `LaravelConfig::$values` including `accord.spec_source => 'file'` and `accord.spec_pattern => '{base}/Fixtures/{version}'`. The global `base_path()` stub returns `dirname(__DIR__)` (the `tests/` dir).

- [ ] **Step 1: Write the failing tests**

Add these imports to the `Fissible\Accord\Tests\Feature` namespace `use` block (some may already be present — do not duplicate):

```php
    use Fissible\Accord\SpecSourceInterface;
    use Fissible\Accord\Tests\Support\ArrayCache;
    use Psr\SimpleCache\CacheInterface;
```

Add `'accord.spec_cache' => null,` to the `LaravelConfig::$values` array in `setUp()`.

Add a `FakeCacheManager` class inside the `namespace Fissible\Accord\Tests\Feature { ... }` block (next to the other fake classes like `FakeLogManager`):

```php
    final class FakeCacheManager
    {
        public function __construct(private readonly CacheInterface $cache) {}

        public function store(?string $name = null): CacheInterface
        {
            return $this->cache;
        }
    }
```

Add these two methods to the `LaravelServiceProviderTest` class (8-space method indentation, 12-space body, matching siblings):

```php
        public function test_file_spec_cache_wired_from_config(): void
        {
            $cache = new ArrayCache();
            LaravelConfig::$values['accord.spec_cache']   = true;
            LaravelConfig::$values['accord.spec_source']  = 'file';
            LaravelConfig::$values['accord.spec_pattern'] = '{base}/Fixtures/{version}';

            $app = new FakeLaravelContainer([
                LoggerInterface::class => new RecordingLogger(),
                'cache'                => new FakeCacheManager($cache),
            ]);
            (new AccordServiceProvider($app))->register();

            $app->make(SpecSourceInterface::class)->load('v1');

            $this->assertNotEmpty($cache->store); // resolved default store cached the parsed spec
        }

        public function test_url_spec_cache_wired_from_config(): void
        {
            $cache = new ArrayCache();
            LaravelConfig::$values['accord.spec_cache']   = true;
            LaravelConfig::$values['accord.spec_source']  = 'url';
            LaravelConfig::$values['accord.spec_pattern'] = 'file://' . dirname(__DIR__) . '/Fixtures/{version}.yaml';

            $app = new FakeLaravelContainer([
                LoggerInterface::class => new RecordingLogger(),
                'cache'                => new FakeCacheManager($cache),
            ]);
            (new AccordServiceProvider($app))->register();

            $app->make(SpecSourceInterface::class)->load('v1');

            $this->assertNotEmpty($cache->store); // UrlSpecSource now receives the cache (previously only a TTL)
        }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter LaravelServiceProviderTest`
Expected: FAIL — the provider builds the sources WITHOUT a cache, so `$cache->store` stays empty.

- [ ] **Step 3: Update the provider**

In `src/Drivers/Laravel/Providers/AccordServiceProvider.php`, add the import near the existing `use` statements:

```php
use Psr\SimpleCache\CacheInterface;
```

Replace the `SpecSourceInterface` singleton closure with:

```php
        $this->app->singleton(SpecSourceInterface::class, function () {
            $type    = config('accord.spec_source', 'file');
            $pattern = config('accord.spec_pattern');
            $cache   = $this->resolveSpecCache();
            $ttl     = (int) config('accord.spec_cache_ttl', 3600);

            if ($type === 'url') {
                return new UrlSpecSource(pattern: $pattern, cache: $cache, ttl: $ttl);
            }

            return new FileSpecSource(base_path(), $pattern, $cache, $ttl);
        });
```

Add this private helper method to the class (next to the other private helpers like `resolveLogger`):

```php
    private function resolveSpecCache(): ?CacheInterface
    {
        $store = config('accord.spec_cache');

        if ($store === null || $store === false || $store === '') {
            return null;
        }

        return $store === true
            ? $this->app->make('cache')->store()        // default store (NO argument — avoids store('1'))
            : $this->app->make('cache')->store($store);  // named store
    }
```

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 143 prior + 2 = 145 tests. Deprecations 5. Existing provider tests still pass (default `spec_cache => null` → no cache → unchanged behavior).

- [ ] **Step 5: Commit**

```bash
git add src/Drivers/Laravel/Providers/AccordServiceProvider.php tests/Feature/LaravelServiceProviderTest.php
git commit -m "feat: provider resolves spec cache and wires it into file + url sources (#7)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `AccordFactory` — wire cache into the file source

**Files:**
- Modify: `src/AccordFactory.php`
- Test: `tests/Unit/AccordFactoryTest.php`

The current `makeSpecSource` file branch (the URL branch already passes `$config['spec_cache']`):

```php
        return new FileSpecSource(
            $basePath,
            $config['spec_pattern'] ?? '{base}/resources/openapi/{version}',
        );
```

- [ ] **Step 1: Write the failing test**

In `tests/Unit/AccordFactoryTest.php` (namespace `Fissible\Accord\Tests\Unit`; it already imports `AccordFactory`, `Nyholm\Psr7\ServerRequest`, and has a `passthroughHandler()` helper), add `use Fissible\Accord\Tests\Support\ArrayCache;` and this test:

```php
    public function test_factory_wires_file_spec_cache(): void
    {
        $cache = new ArrayCache();

        $middleware = AccordFactory::make(
            ['spec_source' => 'file', 'spec_pattern' => '{base}/{version}', 'spec_cache' => $cache],
            dirname(__DIR__) . '/Fixtures',
        );

        // GET /v1/users loads the spec (then skips as missing_request_schema — no params/body, no throw).
        $middleware->process(new ServerRequest('GET', '/v1/users'), $this->passthroughHandler());

        $this->assertNotEmpty($cache->store);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AccordFactoryTest`
Expected: FAIL — the factory's `FileSpecSource` is built without the cache, so `$cache->store` stays empty.

- [ ] **Step 3: Pass the cache in the factory**

In `src/AccordFactory.php`, replace the file-branch `return new FileSpecSource(...)` shown above with:

```php
        return new FileSpecSource(
            $basePath,
            $config['spec_pattern'] ?? '{base}/resources/openapi/{version}',
            $config['spec_cache'] ?? null,
            (int) ($config['spec_cache_ttl'] ?? 3600),
        );
```

Also update the class docblock's "Config keys (all optional):" list — the `spec_cache` entry currently (if present) implies URL-only; ensure there is a line describing it for both sources. Add or adjust to:

```php
 *   spec_cache       — Psr\SimpleCache\CacheInterface|null; persists the parsed spec across
 *                      processes (file + url sources)         (default: null = in-process only)
```

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 145 prior + 1 = 146 tests. Deprecations 5.

- [ ] **Step 5: Commit**

```bash
git add src/AccordFactory.php tests/Unit/AccordFactoryTest.php
git commit -m "feat: AccordFactory wires spec_cache into the file source (#7)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: README documentation

**Files:**
- Modify: `README.md`

No test (docs).

- [ ] **Step 1: Add config lines**

In `README.md`, find the Laravel published-config snippet (it contains `'spec_pattern' => ...` and `'spec_cache_ttl' => ...`). Add a `spec_cache` line immediately after the `spec_pattern` line:

```php
    'spec_cache'     => env('ACCORD_SPEC_CACHE', null),         // null|true|'store' — persist the parsed spec
```

- [ ] **Step 2: Add a "Caching the spec" subsection**

Immediately after that config snippet's closing ``` fence, insert this markdown:

```markdown
**Caching the spec.** `FileSpecSource` parses the OpenAPI file on every `load()`, and in
PHP-FPM each request is a fresh process — so the (slow) YAML parse runs per request. Enable
a persistent cache to parse once and rehydrate from cached JSON on subsequent requests
(roughly an order of magnitude faster):

- **`spec_cache`** — `null`/`false` = off (in-process cache only; the default), `true` = the
  application's default cache store, or a store name (e.g. `'redis'`). The resolved cache is
  wired into both file and URL sources.
- **Invalidation is automatic for files:** the cache key includes the spec file's
  modification time, so a redeployed/edited spec produces a new key and is re-parsed — no
  `cache:clear` needed. `spec_cache_ttl` is just a backstop that evicts stale old-mtime
  entries.

Two caveats:

- **Long-lived workers (Octane/RoadRunner):** `ContractValidator` keeps an in-process parsed
  spec per version for the life of the worker, so mtime invalidation only helps fresh
  processes (PHP-FPM). Restart workers on deploy (these stacks already do) to pick up a
  changed spec.
- **External `$ref`s:** the cache stores the spec's *serialized data*, so specs that rely on
  **external-file** `$ref`s may not round-trip — keep specs self-contained. Internal
  `#/components` refs are fine.

For Slim/Mezzio, pass a PSR-16 cache instance directly: `AccordFactory::make(['spec_cache' => $psr16, ...], $basePath)`.
```

- [ ] **Step 3: Verify suite + fences**

Run: `vendor/bin/phpunit --colors=never` → 146 tests, 5 deprecations.
Run: `grep -c '```' README.md` → must be EVEN.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: document spec caching (config, mtime invalidation, caveats) (#7)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Final verification

- [ ] **Step 1: Full suite + clean tree**

```bash
vendor/bin/phpunit --colors=never
git diff --check
```
Expected: 146 tests pass, 5 deprecations (no new). `git diff --check` prints nothing.

- [ ] **Step 2: Core stayed framework-agnostic**

```bash
grep -rn 'Illuminate\\' src/ | grep -v src/Drivers/
```
Expected: no output.

- [ ] **Step 3: Push + PR (only if the user asks)**

Do not push/PR unless asked. When asked:

```bash
git push -u origin feat/file-spec-cache
gh pr create --title "feat: persistent cache for file-backed specs (#7)" --body "$(cat <<'EOF'
Implements #7. Persists the parsed spec across requests so PHP-FPM doesn't re-parse YAML every request.

- `FileSpecSource` gains an optional PSR-16 cache (mirrors `UrlSpecSource`). Miss → parse + store `json_encode(getSerializableData())`; hit → `Reader::readFromJson` (~15× faster than YAML parse, benchmarked).
- Cache key includes the file's **mtime** → a redeployed spec auto-invalidates (no flush); TTL evicts stale entries.
- Resilient: cache get/set failures and invalid-JSON entries fall back to parsing; malformed local specs still throw.
- Laravel `spec_cache` config (null | true | store-name) + provider wiring — also **fixes the dead URL-cache wiring** (the provider previously passed only a TTL to `UrlSpecSource`). `AccordFactory` wires the file cache too.
- Docs cover mtime invalidation + the long-lived-worker and external-`$ref` caveats.

Backward compatible: cache defaults to null everywhere → unchanged behavior; new ctor params trailing.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes (author)

- **Spec coverage:** ArrayCache double (T1), FileSpecSource cache + mtime key + JSON round-trip + corrupt/failure resilience + internal-ref fidelity + v3 fixture (T2), config `spec_cache` + corrected `spec_cache_ttl` doc (T3), provider resolveSpecCache (true→default store, not store('1')) + wire both sources + file & url tests (T4), factory file-cache wiring (T5), README incl. both caveats (T6), framework-agnostic guard (T7). The "fix dead URL wiring" requirement is covered by T4's `test_url_spec_cache_wired_from_config`.
- **Type consistency:** `FileSpecSource(basePath, pattern, ?CacheInterface $cache = null, int $ttl = 3600)` used identically in T2/T4/T5; key format `fissible.accord.spec.file.{xxh32}.{mtime}`; `resolveSpecCache(): ?CacheInterface`; ArrayCache `$store` public array used across tests.
- **No placeholders:** every code step shows full code; the round-trip + mtime behavior were reality-checked (benchmark + ref probe) before writing; expected counts cumulative (137→146).
