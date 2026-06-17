# Exploded-Array Query Params Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validate exploded array query params (`?tags=a&tags=b`, OpenAPI `form`/`explode:true`) correctly, instead of seeing only the last value.

**Architecture:** PSR-7 `getQueryParams()` collapses repeated keys (it's `parse_str`). For an array-typed query param, recover all values from the raw query string when there are 2+ occurrences; otherwise fall back to the existing path unchanged. One private change in `ContractValidator`.

**Tech Stack:** PHP 8.2+, PHPUnit 11, cebe/php-openapi, nyholm/psr7.

**Spec:** `docs/superpowers/specs/2026-06-17-exploded-array-query-params-design.md`

**Branch:** `feat/exploded-array-query-params` (already checked out, based on `main`).

**Conventions:** `declare(strict_types=1)`; core framework-agnostic (`ContractValidator` is `Fissible\Accord`). Run the suite with `vendor/bin/phpunit --colors=never`. **Baseline: 146 tests passing, 8 deprecations** (pre-existing `vendor/cebe` `$ref` noise; the v6 fixture has no `$ref`, so the count stays 8).

---

## Task 1: Recover repeated query keys for array params

**Files:**
- Create: `tests/Fixtures/v6.yaml`
- Modify: `src/ContractValidator.php`
- Test: `tests/Feature/ContractValidatorTest.php`

The current `parameterValue` query branch in `src/ContractValidator.php`:
```php
        if ($parameter->in === 'query') {
            $query = $request->getQueryParams();

            return array_key_exists($parameter->name, $query)
                ? [true, $query[$parameter->name]]
                : [false, null];
        }
```
`Schema` is already imported. `tests/Feature/ContractValidatorTest.php` (namespace `Fissible\Accord\Tests\Feature`) has `makeValidator()` and imports `ServerRequest`. The default `VersionExtractor` maps `/v6/...` → `v6` → `tests/Fixtures/v6.yaml`. The existing `v1.yaml` `/v1/roster` has a query param `ids` (`style: form, explode: false`, `array<integer>`), a required query `page` (`integer, minimum 1`), and a required header `X-Client` (`string, minLength 3`).

- [ ] **Step 1: Create the exploded fixture** `tests/Fixtures/v6.yaml`:

```yaml
openapi: '3.0.3'
info:
  title: Exploded Query
  version: '6'
paths:
  /v6/items:
    get:
      operationId: items.index
      parameters:
        - name: tags
          in: query
          required: true
          style: form
          explode: true
          schema:
            type: array
            items: { type: integer }
      responses:
        '200':
          description: OK
```

- [ ] **Step 2: Write the failing tests**

Add these methods to `tests/Feature/ContractValidatorTest.php`:

```php
    public function test_exploded_array_query_param_validates_every_element(): void
    {
        $result = $this->makeValidator()->validateRequest(
            new ServerRequest('GET', '/v6/items?tags=1&tags=2'),
        );

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_exploded_array_query_param_catches_bad_element(): void
    {
        $result = $this->makeValidator()->validateRequest(
            new ServerRequest('GET', '/v6/items?tags=1&tags=abc'),
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('tags', implode("\n", $result->errors));
    }

    public function test_single_value_array_query_param_still_works(): void
    {
        $result = $this->makeValidator()->validateRequest(
            new ServerRequest('GET', '/v6/items?tags=1'),
        );

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_missing_required_array_query_param_still_reported(): void
    {
        $result = $this->makeValidator()->validateRequest(
            new ServerRequest('GET', '/v6/items'),
        );

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('tags', implode("\n", $result->errors));
    }

    public function test_comma_delimited_array_query_param_unchanged(): void
    {
        // /v1/roster ids is style: form, explode: false → comma-delimited, single occurrence.
        $request = (new ServerRequest('GET', '/v1/roster?page=1&ids=1,2,3'))
            ->withHeader('X-Client', 'abc');

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_bracket_array_query_param_unchanged(): void
    {
        // ?ids[]=1&ids[]=2 → getQueryParams yields ['1','2']; repeatedQueryValues finds 0 for plain "ids".
        $request = (new ServerRequest('GET', '/v1/roster?page=1&ids[]=1&ids[]=2'))
            ->withHeader('X-Client', 'abc');

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ContractValidatorTest`
Expected: FAIL — `test_exploded_array_query_param_validates_every_element` (and the bad-element test): `getQueryParams()` returns only `tags=2`, so the param is `['2']` (or `2` split) — the exploded `1` is lost, so the bad-element test won't see two elements and the "validates every element" expectation diverges. (At minimum the bad-element test fails because only `2` survives.)

- [ ] **Step 4: Implement the fix**

In `src/ContractValidator.php`:

(a) Replace the `parameterValue` query branch shown above with:

```php
        if ($parameter->in === 'query') {
            $schema = $parameter->schema;

            if ($schema instanceof Schema && $schema->type === 'array') {
                $repeated = $this->repeatedQueryValues($request->getUri()->getQuery(), $parameter->name);

                if (count($repeated) > 1) {
                    return [true, $repeated];   // exploded repeated keys (parse_str keeps only the last)
                }
            }

            $query = $request->getQueryParams();

            return array_key_exists($parameter->name, $query)
                ? [true, $query[$parameter->name]]
                : [false, null];
        }
```

(b) Add this private method (place it just after `parameterValue`):

```php
    /** @return string[] */
    private function repeatedQueryValues(string $rawQuery, string $name): array
    {
        $values = [];

        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (urldecode($key) === $name) {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }
```

Do not change any other method.

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 146 prior + 6 new = 152 tests. Deprecations remain 8 (v6 has no `$ref`).

- [ ] **Step 6: Commit**

```bash
git add src/ContractValidator.php tests/Feature/ContractValidatorTest.php tests/Fixtures/v6.yaml
git commit -m "fix: validate exploded array query params (form/explode repeated keys) (#13)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Final verification

- [ ] **Step 1: Full suite + clean tree**

```bash
vendor/bin/phpunit --colors=never
git diff --check
```
Expected: 152 tests pass, 8 deprecations (no new). `git diff --check` prints nothing.

- [ ] **Step 2: Core stayed framework-agnostic**

```bash
grep -rn 'Illuminate\\' src/ | grep -v src/Drivers/
```
Expected: no output.

- [ ] **Step 3: Push + PR (only if the user asks)**

Do not push/PR unless asked. When asked:

```bash
git push -u origin feat/exploded-array-query-params
gh pr create --title "fix: exploded-array query parameter validation (#13)" --body "$(cat <<'EOF'
Implements #13 (closes #13).

PSR-7 `getQueryParams()` is `parse_str`, which collapses repeated query keys to the last value — so an exploded array param (`?tags=a&tags=b`, OpenAPI's default `form`/`explode:true`) was validated against only `b`. Fix: for an array-typed query param, recover all values from the raw query string when there are 2+ occurrences of the key; otherwise fall back to the existing `getQueryParams()` path.

Gated on array schema + 2+ raw occurrences, so bracket arrays (`?ids[]=1&ids[]=2`), comma-delimited (`?ids=1,2,3`), single values, and non-array params are unchanged. Private to `ContractValidator`; backward compatible.

## Test Plan
- [x] `vendor/bin/phpunit` — **152 tests pass** (was 146)
- [x] Exploded repeated keys validate every element + catch a bad element; single value works; required-missing reported; comma + bracket forms unchanged
- [x] `git diff --check` clean; core framework-agnostic

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes (author)

- **Spec coverage:** parameterValue array-query recovery + `repeatedQueryValues` (Task 1); v6 fixture; tests for exploded/bad-element/single/missing/comma-unchanged/bracket-unchanged; framework-agnostic guard (Task 2). Every spec test bullet maps to a Task 1 test.
- **Type consistency:** `repeatedQueryValues(string, string): string[]`; gated on `Schema` array type; returns `[true, string[]]` which `deserializeParameterValue` already array-handles.
- **No placeholders:** full code in every step; the parse_str/getQueryParams behavior is the documented root cause; counts cumulative (146→152), deprecations stay 8.
