# Wildcard Media Types + `servers` Base Paths Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop silently skipping operations Accord *should* validate — match wildcard media types (`application/*`, `*/*`) and account for OpenAPI `servers` base paths during operation lookup.

**Architecture:** Two additive changes in `ContractValidator`. (1) A `matchMediaType` helper does exact → `{type}/*` → `*/*` content negotiation in both request and response lookups. (2) Operation lookup becomes method-aware and, when an as-is path doesn't match, retries against the request path with each root-level `servers` base-path prefix stripped (segment-safe); the *effective* matched path is threaded into path-parameter extraction.

**Tech Stack:** PHP 8.2+, PHPUnit 11, cebe/php-openapi, nyholm/psr7.

**Spec:** `docs/superpowers/specs/2026-06-17-wildcard-media-and-server-paths-design.md`

**Branch:** `feat/media-and-server-paths` (already checked out).

**Conventions:** `declare(strict_types=1)`; core stays framework-agnostic (`ContractValidator` is namespace `Fissible\Accord`). Run the suite with `vendor/bin/phpunit --colors=never`. **Baseline: 146 tests passing, 8 deprecations** (all `vendor/cebe` `$ref`/`resolveReferences` noise — the v4/v5/v50 fixtures here have NO `$ref`s, so the count stays 8).

---

## File Structure

**Core (modify):** `src/ContractValidator.php` — `matchMediaType` (new), `serverBasePaths` (new), `matchPathItem` (new), method-aware `findPathItem`/`findOperation`, `validateRequest`/`validateResponse` lookups, `validateParameters` signature.

**Tests (create):** `tests/Fixtures/v4.yaml` (wildcard media), `tests/Fixtures/v5.yaml` (servers base path), `tests/Fixtures/v50.yaml` (segment-safety).

**Tests (modify):** `tests/Feature/ContractValidatorTest.php`.

**Docs (modify):** `README.md`.

---

## Task 1: Wildcard media-type matching

**Files:**
- Create: `tests/Fixtures/v4.yaml`
- Modify: `src/ContractValidator.php`
- Test: `tests/Feature/ContractValidatorTest.php`

- [ ] **Step 1: Create the wildcard fixture** `tests/Fixtures/v4.yaml`:

```yaml
openapi: '3.0.3'
info:
  title: Wildcard Media
  version: '4'
paths:
  /v4/exact-wins:
    get:
      operationId: exactWins
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                type: object
                required: [id]
                properties:
                  id: { type: integer }
                additionalProperties: false
            application/*:
              schema: { type: string }
  /v4/subtype:
    get:
      operationId: subtype
      responses:
        '200':
          description: OK
          content:
            application/*:
              schema:
                type: object
                required: [id]
                properties:
                  id: { type: integer }
  /v4/anytype:
    get:
      operationId: anytype
      responses:
        '200':
          description: OK
          content:
            '*/*':
              schema:
                type: object
                required: [id]
                properties:
                  id: { type: integer }
  /v4/exact-only:
    get:
      operationId: exactOnly
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema: { type: object }
  /v4/upload:
    post:
      operationId: upload
      requestBody:
        content:
          application/*:
            schema:
              type: object
              required: [name]
              properties:
                name: { type: string }
      responses:
        '200':
          description: OK
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/ContractValidatorTest.php` (namespace `Fissible\Accord\Tests\Feature`) already imports `SkipReason`, `ContractValidator`, `ServerRequest`, `Response`, etc., and has a `makeValidator()` helper and a `jsonResponse(int $status, string $body, string $type = 'application/json'): Response` helper. Add these methods to the class:

```php
    public function test_exact_media_type_wins_over_wildcard(): void
    {
        // /v4/exact-wins declares application/json (object) AND application/* (string).
        // A JSON object body is valid for the exact schema, invalid for the wildcard.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{"id":1}', 'application/json'),
            new ServerRequest('GET', '/v4/exact-wins'),
        );

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_subtype_wildcard_matches_concrete_media_type(): void
    {
        // /v4/subtype declares only application/*; a JSON response is validated against it.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'application/json'), // missing required id
            new ServerRequest('GET', '/v4/subtype'),
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_full_wildcard_matches_any_media_type(): void
    {
        // /v4/anytype declares only */*; even a text/plain response is validated.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'text/plain'),
            new ServerRequest('GET', '/v4/anytype'),
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_wildcard_derivation_is_case_insensitive(): void
    {
        // Application/JSON (mixed case) → application/* via lowercased derivation.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'Application/JSON'),
            new ServerRequest('GET', '/v4/subtype'),
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_unmatched_media_type_still_skips(): void
    {
        // /v4/exact-only declares only application/json; text/plain has no wildcard fallback.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'text/plain'),
            new ServerRequest('GET', '/v4/exact-only'),
        );

        $this->assertSame(SkipReason::UnsupportedMediaType, $result->skipReason);
    }

    public function test_request_body_wildcard_media_is_matched(): void
    {
        // /v4/upload requestBody declares application/*; a JSON body is validated against it.
        $request = (new ServerRequest('POST', '/v4/upload'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{}')); // missing required name

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ContractValidatorTest`
Expected: FAIL — wildcard media types aren't matched (the wildcard ones currently skip `UnsupportedMediaType`, so the invalid-body asserts fail; exact-wins may already pass).

- [ ] **Step 4: Add `matchMediaType` and use it in both lookups**

In `src/ContractValidator.php`:

(a) Add the import after the existing `use cebe\openapi\spec\...` lines (alongside `Operation`, `PathItem`, `Schema`):

```php
use cebe\openapi\spec\MediaType;
```

(b) In `validateRequest`, replace the request-body media lookup line:

```php
            $mediaType   = $operation->requestBody->content[$contentType] ?? null;
```

with:

```php
            $mediaType   = $this->matchMediaType($operation->requestBody->content, $contentType);
```

(c) In `validateResponse`, replace the response media lookup line:

```php
        $mediaType   = $specResponse->content[$contentType] ?? null;
```

with:

```php
        $mediaType   = $this->matchMediaType($specResponse->content, $contentType);
```

(d) Add the helper method (place it just before `parseContentType`):

```php
    /** @param array<string, MediaType> $content */
    private function matchMediaType(array $content, string $contentType): ?MediaType
    {
        if (isset($content[$contentType])) {
            return $content[$contentType];                 // exact (as parsed) wins
        }

        $lower = strtolower($contentType);
        $type  = explode('/', $lower)[0];

        return $content[$type . '/*']                      // e.g. application/*
            ?? $content['*/*']                             // full wildcard
            ?? null;
    }
```

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 146 prior + 6 new = 152 tests. Deprecations remain 8 (v4 has no `$ref`s).

- [ ] **Step 6: Commit**

```bash
git add src/ContractValidator.php tests/Feature/ContractValidatorTest.php tests/Fixtures/v4.yaml
git commit -m "feat: wildcard media-type matching (exact > type/* > */*) for request + response (#10)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `servers` base paths + method-aware lookup + effective-path threading

**Files:**
- Create: `tests/Fixtures/v5.yaml`, `tests/Fixtures/v50.yaml`
- Modify: `src/ContractValidator.php`
- Test: `tests/Feature/ContractValidatorTest.php`

The current relevant methods are: `validateRequest` (computes `$method`, then `$match = $this->findPathItem($spec, $path)`, then `validateParameters($operation, $match['pathItem'], $match['template'], $request)`); `findOperation($spec, $method, $path)` → `findPathItem` → `getOperations()[$method]`; `findPathItem($spec, $path)` (iterates `$spec->paths`, returns `['template','pathItem']`); `validateParameters(Operation, PathItem, string $template, ServerRequestInterface $request)` (calls `extractPathParameters($template, $request->getUri()->getPath())`).

- [ ] **Step 1: Create the fixtures**

`tests/Fixtures/v5.yaml`:

```yaml
openapi: '3.0.3'
info:
  title: Server Base
  version: '5'
servers:
  - url: /v5
paths:
  /users:
    get:
      operationId: users.index
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                type: array
                items: { type: object }
  /users/{id}:
    parameters:
      - name: id
        in: path
        required: true
        schema: { type: integer }
    get:
      operationId: users.show
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema: { type: object }
```

`tests/Fixtures/v50.yaml`:

```yaml
openapi: '3.0.3'
info:
  title: Server Base 50
  version: '50'
servers:
  - url: /v5
paths:
  /users:
    get:
      operationId: users.index
      responses:
        '200':
          description: OK
```

- [ ] **Step 2: Write the failing tests**

Add these methods to `tests/Feature/ContractValidatorTest.php`:

```php
    public function test_server_base_path_matches_relative_operation(): void
    {
        // v5.yaml has servers: [{url: /v5}] and a relative path /users.
        // Request /v5/users should match after stripping the base — validated, not skipped.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '[]', 'application/json'),
            new ServerRequest('GET', '/v5/users'),
        );

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_path_params_extracted_on_stripped_route(): void
    {
        // /v5/users/5 → strip /v5 → /users/5 against template /users/{id}.
        // With effective-path threading, id=5 is extracted and validates (integer).
        // WITHOUT threading, id would be "missing required" and this would be invalid.
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v5/users/5'));

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_server_base_stripping_is_segment_safe(): void
    {
        // /v50/users loads v50.yaml (base /v5). /v5 must NOT be stripped from /v50/... .
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v50/users'));

        $this->assertSame(SkipReason::UnmatchedOperation, $result->skipReason);
    }

    public function test_full_path_spec_still_matches_as_is(): void
    {
        // v1.yaml has no servers; its full-path /v1/users must still match as-is (backward compat).
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '[{"id":1,"name":"a"}]', 'application/json'),
            new ServerRequest('GET', '/v1/users'),
        );

        $this->assertTrue($result->valid);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ContractValidatorTest`
Expected: FAIL — `test_server_base_path_matches_relative_operation` and `test_path_params_extracted_on_stripped_route` fail (request `/v5/users` doesn't match the relative `/users` template → `UnmatchedOperation`).

- [ ] **Step 4: Implement method-aware lookup + base-path fallback + threading**

In `src/ContractValidator.php`:

(a) Replace `findOperation` with (passes `$method` to `findPathItem`):

```php
    private function findOperation(OpenApi $spec, string $method, string $path): ?Operation
    {
        $match = $this->findPathItem($spec, $path, $method);

        return $match === null ? null : ($match['pathItem']->getOperations()[$method] ?? null);
    }
```

(b) Replace `findPathItem` with the method-aware, base-path-fallback version, and add `matchPathItem` + `serverBasePaths` right after it:

```php
    /** @return array{template: string, pathItem: PathItem, path: string}|null */
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

    /** @return array{template: string, pathItem: PathItem, path: string}|null */
    private function matchPathItem(OpenApi $spec, string $path, string $method): ?array
    {
        foreach ($spec->paths as $template => $pathItem) {
            if (!$pathItem instanceof PathItem || !$this->pathMatches($template, $path)) {
                continue;
            }

            if (($pathItem->getOperations()[$method] ?? null) === null) {
                continue;   // path matches but not this method → keep looking (allow base-path fallback)
            }

            return ['template' => $template, 'pathItem' => $pathItem, 'path' => $path];
        }

        return null;
    }

    /** @return string[] */
    private function serverBasePaths(OpenApi $spec): array
    {
        $bases = [];

        foreach ($spec->servers ?? [] as $server) {
            $base = rtrim((string) (parse_url($server->url, PHP_URL_PATH) ?? ''), '/');

            if ($base !== '' && $base !== '/') {
                $bases[$base] = $base;   // dedupe
            }
        }

        return array_values($bases);
    }
```

(c) In `validateRequest`, change the `findPathItem` call to pass `$method`:

```php
        $match  = $this->findPathItem($spec, $path, $method);
```

and change the `validateParameters` call to pass the effective path `$match['path']`:

```php
        [$paramErrors, $paramsEvaluated] = $this->validateParameters(
            $operation,
            $match['pathItem'],
            $match['template'],
            $match['path'],
            $request,
        );
```

(d) Change `validateParameters` to accept the effective `$path` and extract path params against it:

```php
    /** @return array{0: string[], 1: int} */
    private function validateParameters(
        Operation $operation,
        PathItem $pathItem,
        string $template,
        string $path,
        ServerRequestInterface $request,
    ): array {
        $errors         = [];
        $evaluated      = 0;
        $pathParameters = $this->extractPathParameters($template, $path);
```

(leave the rest of `validateParameters` — the `foreach` loop and `return [$errors, $evaluated];` — unchanged).

Note: `OpenApi`, `Operation`, `PathItem` are already imported. `parse_url`/`str_starts_with`/`substr`/`rtrim` are builtins. Do not change `pathMatches`, `extractPathParameters`, or any other method.

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 152 prior + 4 new = 156 tests. Deprecations remain 8. All existing operation-matching tests still pass (as-is matching is tried first and is method-aware, preserving prior `UnmatchedOperation` outcomes).

- [ ] **Step 6: Commit**

```bash
git add src/ContractValidator.php tests/Feature/ContractValidatorTest.php tests/Fixtures/v5.yaml tests/Fixtures/v50.yaml
git commit -m "feat: servers base-path fallback in operation lookup (method-aware, effective-path threading) (#10)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: README documentation

**Files:**
- Modify: `README.md`

No test (docs).

- [ ] **Step 1: Add a "Path & content-type matching" note**

In `README.md`, find a sensible spot in the spec/validation discussion (e.g. after the "Diagnostics" or near where operation matching / content types are mentioned — grep for `unsupported_media_type` or `unmatched_operation`). Insert this short subsection:

```markdown
**Path & content-type matching.** Accord matches the request path against your spec's path
templates as-is first. If nothing matches, it also tries stripping each root-level
`servers` base path — so a spec with `servers: [{url: /v2}]` and a relative path `/users`
matches a request to `/v2/users`. (Stripping is segment-safe: `/v20/...` is not treated as
under `/v2`. Path-item/operation-level `servers` overrides are not considered, and the API
version must still appear in the request path or be matched by your `version_pattern`.)

Content types are matched exact-first, then by wildcard: a request/response `application/json`
will match a spec that declares `application/*` or `*/*` (exact declarations always win).
```

- [ ] **Step 2: Verify suite + fences**

Run: `vendor/bin/phpunit --colors=never` → 156 tests, 8 deprecations.
Run: `grep -c '```' README.md` → must be EVEN.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document servers base-path and wildcard media-type matching (#10)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Final verification

- [ ] **Step 1: Full suite + clean tree**

```bash
vendor/bin/phpunit --colors=never
git diff --check
```
Expected: 156 tests pass, 8 deprecations (no new — the new fixtures have no `$ref`s). `git diff --check` prints nothing.

- [ ] **Step 2: Core stayed framework-agnostic**

```bash
grep -rn 'Illuminate\\' src/ | grep -v src/Drivers/
```
Expected: no output.

- [ ] **Step 3: Push + PR (only if the user asks)**

Do not push/PR unless asked. When asked:

```bash
git push -u origin feat/media-and-server-paths
gh pr create --title "feat: wildcard media types + servers base paths (#10)" --body "$(cat <<'EOF'
Implements #10. Stops silently skipping operations Accord should validate.

- **Wildcard media types:** `matchMediaType` negotiates exact → `{type}/*` → `*/*` in both request and response lookups (exact wins; wildcard derivation is case-insensitive).
- **`servers` base paths:** operation lookup tries the request path as-is, then (method-aware) retries against the path with each root-level `servers` base prefix stripped — segment-safe (`/v20` ≠ under `/v2`). The effective matched path is threaded into path-parameter extraction, so path params on a server-base route extract correctly.
- Scope: root-level `servers` only; version extraction unchanged.

Backward compatible: as-is path matching and exact media matching are tried first; wildcards/base-paths only add matches that previously surfaced as `unsupported_media_type` / `unmatched_operation` skips. All changes are private to `ContractValidator`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes (author)

- **Spec coverage:** wildcard matching (exact/subtype/full, both directions, case-insensitive, no-match-skips) → Task 1; serverBasePaths + method-aware findPathItem/matchPathItem + as-is-then-strip + segment-safe + normalize + effective-path threading via validateParameters → Task 2; README → Task 3; framework-agnostic guard → Task 4. The method-aware change (review fix) is in `matchPathItem`; the v50 segment-safety fixture (review fix) is in Task 2.
- **Type consistency:** `matchMediaType(array, string): ?MediaType`; `findPathItem(OpenApi, string $path, string $method)` and `matchPathItem(...)` both return `array{template,pathItem,path}`; `serverBasePaths(OpenApi): string[]`; `validateParameters(Operation, PathItem, string $template, string $path, ServerRequestInterface)` — `$match['path']` threaded consistently.
- **No placeholders:** every code step shows the exact old→new lines / full methods; cebe `servers`/wildcard-key access was reality-checked; expected counts cumulative (146→156); deprecation baseline 8 (new fixtures add none).
