# Fix exploded-array query parameter validation (#13)

**Date:** 2026-06-17
**Issue:** #13 (exploded-array query params, form/explode)
**Package state:** accord is at v1.3.0. This is a bug fix; backward compatible.

## Problem

`ContractValidator::parameterValue` reads query params via PSR-7 `getQueryParams()`, which is
`parse_str` of the query string. `parse_str` **collapses repeated keys to the last value**.
OpenAPI's default array serialization for a query parameter is `style: form, explode: true`,
whose wire form is repeated keys (`?tags=a&tags=b`). So an exploded array arrives as the
scalar `'b'`, and the parameter is validated against `['b']` — silently wrong.

The other shapes already work and must keep working:
- comma-delimited `?tags=a,b,c` (`style: form, explode: false`) → `parse_str` keeps `'a,b,c'`,
  which the existing `splitArrayParameterValue` comma-splits.
- PHP bracket `?tags[]=a&tags[]=b` → `parse_str` yields `['a','b']` (already an array).

## Decision (confirmed with user)

Recover repeated keys from the **raw** query string for **array-typed query params only**,
and only when there are **2+ occurrences** of the key; otherwise fall back to the existing
`getQueryParams()` path unchanged. Gating on *array schema* + *2+ occurrences* means the
change touches **only** the currently-broken repeated-key case — bracket-style arrays and
single/comma values behave exactly as today.

## Architecture

One change in `src/ContractValidator.php` (core, framework-agnostic), entirely private.

### Components

1. **`parameterValue` — query branch** (modified). Before consulting `getQueryParams()`, for a
   query param whose `schema` is `type: array`, scan the raw query for all values of the key:
   ```php
   if ($parameter->in === 'query') {
       $schema = $parameter->schema;

       if ($schema instanceof Schema && $schema->type === 'array') {
           $repeated = $this->repeatedQueryValues($request->getUri()->getQuery(), $parameter->name);

           if (count($repeated) > 1) {
               return [true, $repeated];   // exploded repeated keys recovered (parse_str would lose all but the last)
           }
       }

       $query = $request->getQueryParams();

       return array_key_exists($parameter->name, $query)
           ? [true, $query[$parameter->name]]
           : [false, null];
   }
   ```
   (`Schema` is already imported.)

2. **`repeatedQueryValues(string $rawQuery, string $name): array`** (new, private) — collects
   all decoded values for a key from the raw query string, matching `parse_str` decoding
   (`urldecode`, so `+` → space). Bracket keys (`tags[]`) decode to `tags[]` and therefore do
   NOT match a plain `tags` name (so bracket-style still flows through `getQueryParams`):
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

The downstream flow is unchanged: `parameterValue` returns `[true, ['a','b']]`,
`deserializeParameterValue` sees an array (`is_array` true), skips splitting, and coerces each
element against the items schema; `validateParameterValue` validates the array.

## Data flow (`?tags=1&tags=abc`, schema `array<integer>`)

```
parameterValue(tags, request, ...) →
  schema->type === 'array' → repeatedQueryValues('tags=1&tags=abc', 'tags') → ['1','abc']
  count > 1 → return [true, ['1','abc']]
deserializeParameterValue → is_array → coerce each → [1, 'abc']  ('abc' not coercible to int)
validateParameterValue → array<integer> → 'abc' invalid → error
```

## Error handling / backward compatibility

- The new path triggers ONLY for an array-schema query param with 2+ raw occurrences of the
  key. Every other input (single value, comma/pipe/space-delimited, bracket arrays,
  non-array params, missing params) takes the unchanged `getQueryParams()` path.
- All changes are private to `ContractValidator`. No public API change.

## Testing (TDD, one behavior per test)

New fixture `tests/Fixtures/v6.yaml` — `GET /v6/items` with a required query param `tags`,
`style: form, explode: true`, schema `array` of `integer`.

`ContractValidator` tests (build requests with `(new ServerRequest('GET', '/v6/items?tags=1&tags=2'))`
— Nyholm parses the query string into both the URI query and `getQueryParams()`):
- exploded repeated keys validate every element: `?tags=1&tags=2` → valid, `wasValidated()`.
- a bad element is caught: `?tags=1&tags=abc` → invalid, error mentions `tags`.
- single value still works: `?tags=1` → valid (1 occurrence → fallback → split → `[1]`).
- required-missing still works: `GET /v6/items` (no `tags`) → invalid, "Missing required
  query parameter".
- comma-delimited unchanged (reuse `v1.yaml` `/v1/roster` `ids` param, `form`/`explode:false`):
  `?page=1&ids=1,2,3` + `X-Client: abc` → valid (ids comma-split to `[1,2,3]`).
- bracket array unchanged (reuse `/v1/roster` `ids`): `?page=1&ids[]=1&ids[]=2` + `X-Client: abc`
  → valid (`getQueryParams` yields `['1','2']`; `repeatedQueryValues` finds 0 for plain `ids`).

Whole suite stays green (currently 146 on `main`; the v6 fixture has no `$ref`, so the
deprecation count stays at the `main` baseline of 8).

## Out of scope (YAGNI)

- `deepObject` / nested object query serialization.
- Header/cookie repeated values.
- Re-implementing query parsing for non-array params (a repeated non-array param keeps
  `parse_str`'s last-wins behavior).
