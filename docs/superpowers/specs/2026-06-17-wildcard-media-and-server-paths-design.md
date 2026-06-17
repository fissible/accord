# Wildcard media types + `servers` base paths (#10)

**Date:** 2026-06-17
**Issue:** #10 (support wildcard media types and OpenAPI `servers` base paths)
**Package state:** accord is at v1.3.0. This is additive and backward compatible.

## Problem

`ContractValidator` matches operations and content types exactly:
- A spec declaring `application/*` or `*/*` is silently skipped (`unsupported_media_type`)
  because the lookup is `content[$contentType] ?? null`.
- A spec whose `paths` are relative to a `servers` base path (e.g. `servers: [{url: /v2}]`,
  path `/users`) never matches a request to `/v2/users` — it's a silent
  `unmatched_operation` skip.

Both now surface as #9 skip reasons, which is what makes them findable — and worth fixing.

## Decisions (confirmed with user)

- **Wildcard media types:** match in precedence order **exact → `{type}/*` → `*/*`**, in both
  request and response lookups. Exact always wins.
- **Media type case:** exact lookup uses the parsed content type as-is (first). For wildcard
  *derivation* the content type is lowercased (HTTP media types are case-insensitive), so
  `Application/JSON` derives `application/*`.
- **`servers` base paths:** **additive fallback** — match the request path as-is first
  (today's behavior, full-path specs unchanged); if no operation matches, strip each server
  base-path prefix the request path starts with and retry; first match wins.
- **Segment-safe stripping:** strip a base `B` only when `path === B || str_starts_with(path,
  B . '/')` — so `/v20/users` is NOT considered under base `/v2`.
- **Normalize stripped paths:** stripping `/v2` from `/v2/users` → `/users`; from `/v2` → `/`
  (spec paths need the leading slash).
- **Scope:** only the root-level `OpenApi::$servers` is read. Path-item-level and
  operation-level `servers` overrides (which OpenAPI allows) are explicitly **out of scope**.
  Version extraction is unchanged — the version must still appear in the request path (true
  when the server base path is the version segment) or via a custom `version_pattern`;
  non-version base paths like `/api` are out of scope (a `version_pattern` concern).

## Architecture

All changes are in `src/ContractValidator.php` (core, framework-agnostic). No public API
changes — `findPathItem`'s return shape and `validateParameters`' signature are private.

### Components

1. **`matchMediaType(array $content, string $contentType): ?MediaType`** (new, private) —
   the content-negotiation helper, replacing the two `content[$contentType] ?? null` lookups:
   ```php
   private function matchMediaType(array $content, string $contentType): ?MediaType
   {
       if (isset($content[$contentType])) {
           return $content[$contentType];           // exact (as parsed) wins
       }

       $lower = strtolower($contentType);
       $type  = explode('/', $lower)[0];

       return $content[$type . '/*']                 // e.g. application/*
           ?? $content['*/*']                        // full wildcard
           ?? null;
   }
   ```
   - Request: `$mediaType = $this->matchMediaType($operation->requestBody->content, $contentType);`
   - Response: `$mediaType = $this->matchMediaType($specResponse->content, $contentType);`
   - `$content` values are `MediaType` objects; `MediaType` is `cebe\openapi\spec\MediaType`
     (add a `use`).

2. **`serverBasePaths(OpenApi $spec): string[]`** (new, private) — collect root-level server
   base path prefixes:
   ```php
   private function serverBasePaths(OpenApi $spec): array
   {
       $bases = [];
       foreach ($spec->servers ?? [] as $server) {
           $base = rtrim((string) (parse_url($server->url, PHP_URL_PATH) ?? ''), '/');
           if ($base !== '' && $base !== '/') {
               $bases[$base] = $base;               // dedupe
           }
       }
       return array_values($bases);
   }
   ```

3. **`findPathItem(OpenApi $spec, string $path, string $method): ?array`** (modified) —
   **method-aware**, as-is then strip; returns the **effective matched path**. Method-aware
   so that an as-is path template that matches but lacks the requested method does not
   short-circuit the server-base fallback ("no *operation* matches" → keep trying):
   ```php
   private function findPathItem(OpenApi $spec, string $path, string $method): ?array
   {
       $match = $this->matchPathItem($spec, $path, $method);   // as-is (current behavior)
       if ($match !== null) {
           return $match;
       }

       foreach ($this->serverBasePaths($spec) as $base) {
           if ($path === $base || str_starts_with($path, $base . '/')) {
               $stripped = substr($path, strlen($base));
               if ($stripped === '') {
                   $stripped = '/';
               }
               $match = $this->matchPathItem($spec, $stripped, $method);
               if ($match !== null) {
                   return $match;
               }
           }
       }

       return null;
   }
   ```

4. **`matchPathItem(OpenApi $spec, string $path, string $method): ?array`** (new, private) —
   the extracted current loop, made **method-aware** (a template that matches the path but
   not the method is skipped, so the caller's base-path fallback still runs) and returning
   the effective `path`:
   ```php
   private function matchPathItem(OpenApi $spec, string $path, string $method): ?array
   {
       foreach ($spec->paths as $template => $pathItem) {
           if (! $pathItem instanceof PathItem || ! $this->pathMatches($template, $path)) {
               continue;
           }
           if (($pathItem->getOperations()[$method] ?? null) === null) {
               continue;   // path matches but not this method → keep looking (allow base-path fallback)
           }
           return ['template' => $template, 'pathItem' => $pathItem, 'path' => $path];
       }
       return null;
   }
   ```
   **Note:** `findOperation` (used by `validateResponse`) and `validateRequest` both compute
   `$method = strtolower($request->getMethod())` and pass it down. In `validateRequest`,
   because the match is now method-aware, `$match['pathItem']->getOperations()[$method]` is
   guaranteed non-null when `$match !== null` (the existing defensive `?? null` →
   `UnmatchedOperation` check is kept but unreachable). `findOperation` passes `$method` to
   `findPathItem`:
   ```php
   private function findOperation(OpenApi $spec, string $method, string $path): ?Operation
   {
       $match = $this->findPathItem($spec, $path, $method);
       return $match === null ? null : ($match['pathItem']->getOperations()[$method] ?? null);
   }
   ```

5. **`validateRequest`** (modified) — call `findPathItem` with the method, and pass the
   effective path to parameter extraction. `$method` is already computed before the lookup:
   ```php
   $match = $this->findPathItem($spec, $path, $method);     // now method-aware
   // ... existing null → UnmatchedOperation, and operation fetch (defensive) ...

   [$paramErrors, $paramsEvaluated] = $this->validateParameters(
       $operation,
       $match['pathItem'],
       $match['template'],
       $match['path'],          // effective (possibly-stripped) path — NEW
       $request,
   );
   ```

6. **`validateParameters(Operation, PathItem, string $template, string $path, ServerRequestInterface $request): array`**
   (modified) — extract path params against the effective `$path`, not the raw request URI:
   ```php
   $pathParameters = $this->extractPathParameters($template, $path);   // was $request->getUri()->getPath()
   ```
   Query and header params still read from `$request` (unaffected by base-path stripping).

`findOperation` (used by `validateResponse`) already delegates to `findPathItem`, so response
lookup gets the server-base fallback and wildcard matching for free; responses don't extract
path params, so they need no effective-path threading.

## Data flow (server base `/v2`, spec path `/users/{id}`, request `GET /v2/users/5`)

```
validateRequest('/v2/users/5')
  → findPathItem: matchPathItem('/v2/users/5') → no match
    → base '/v2': '/v2/users/5' starts with '/v2/' → stripped '/users/5'
    → matchPathItem('/users/5') → template '/users/{id}', path '/users/5'
  → validateParameters(..., template='/users/{id}', path='/users/5', request)
    → extractPathParameters('/users/{id}', '/users/5') → {id: '5'}  ✓ (would be empty if raw path used)
```

## Error handling / backward compatibility

- As-is path matching and exact media match are tried first → existing full-path / exact
  specs behave identically.
- Wildcards and base-path stripping only ADD matches that previously skipped
  (`unsupported_media_type` / `unmatched_operation`).
- `findPathItem` return-shape (+`path`) and `validateParameters` signature are private — no
  public API change. `pathMatches`, `extractPathParameters`, etc. unchanged.

## Testing (TDD, one behavior per test)

New fixtures:
- `tests/Fixtures/v4.yaml` — wildcard media types: an op whose response `200` declares
  `application/*` (and another path declaring `*/*`); plus a path with a requestBody under a
  wildcard for the request-side test.
- `tests/Fixtures/v5.yaml` — `servers: [{url: /v5}]` with **relative** paths `/users` and
  `/users/{id}` (`id` integer path param). (Version `v5` so VersionExtractor extracts it from
  `/v5/...`.)
- `tests/Fixtures/v50.yaml` — needed for the segment-safety test: the default
  `VersionExtractor` extracts `v50` from `/v50/users`, so without this fixture that request
  would be `MissingSpec`, not `UnmatchedOperation`. Give it `servers: [{url: /v5}]` and a
  relative path `/users` (same base as v5). A request `GET /v50/users` then loads `v50.yaml`,
  and base `/v5` is correctly NOT stripped from `/v50/users` (segment-safe), so it stays
  `UnmatchedOperation`.

`ContractValidator` tests:
- exact media type wins over `application/*` (a spec declaring both `application/json` and
  `application/*` validates a JSON body/response against the exact schema, not the wildcard).
- `application/*` matches `application/json` (response validated against the wildcard schema).
- `*/*` matches an arbitrary content type.
- case-insensitive wildcard derivation: `Application/JSON` matches `application/*`.
- a content type with no exact and no wildcard match still skips `UnsupportedMediaType`.
- server-base request `GET /v5/users` matches the relative `/users` op (no longer
  `UnmatchedOperation`).
- **path params extract on a stripped route:** `GET /v5/users/5` validates the `id` path
  param (a bad value → invalid; a good value → validated) — proving effective-path threading.
- **segment safety:** `GET /v50/users` (loads `v50.yaml`, whose server base is `/v5`) does
  NOT strip `/v5` from `/v50/users` → stays `UnmatchedOperation` (proves `path === base ||
  starts_with(path, base.'/')`, not plain `starts_with`).
- backward compat: an as-is full-path spec (e.g. existing `v1.yaml` `/v1/users`) still matches
  without any server-base involvement.

Whole suite stays green (currently 146; deprecation baseline 8 from the existing `$ref`
fixture — adding more fixtures with components/refs may add cebe deprecations, which is
vendor noise, not our code).

## Out of scope (YAGNI)

- Path-item-level / operation-level `servers` overrides.
- Making version extraction base-path aware (non-version base paths like `/api`).
- Parameter-level content (`parameter->content`) wildcard matching.
- Server `variables` templating in server URLs.
