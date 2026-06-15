# Strict/debug diagnostics for skipped validation (#9)

**Date:** 2026-06-15
**Issue:** #9 (strict/debug diagnostics for skipped validation)
**Package state:** accord is at v1.1.0. This is additive and backward compatible.

## Problem

Accord fails open for incremental adoption: an unversioned path, a missing spec, an
unmatched operation, a missing request/response schema, or an undeclared media type all
return `ValidationResult::valid(...)` — indistinguishable from a request/response that was
actually validated and passed. So a user can run Accord, see zero violations, and have no
way to know nothing was validated. The Laravel test trait's `assertResponseMatchesContract`
even *passes* on a silently-skipped response.

## Decisions (confirmed with user)

- **Diagnostics only.** Surface *why* nothing was validated; the fail-open posture is
  unchanged (skips keep `valid=true`). Turning skips into violations ("strict enforcement")
  is explicitly out of scope and becomes its own follow-up issue.
- **Result metadata is the primitive.** A framework-agnostic `SkipReason` carried on
  `ValidationResult` (always set on skips, ~free), with optional `debug` logging layered on
  top. Programmatically inspectable by any driver/test; testable without a logger.
- **Param validation counts (precise):** `wasValidated()` is true when **at least one
  schema-backed query, path, or header parameter was actually evaluated** — i.e. its value
  was validated, or it was required-and-missing (a contract check was performed). A
  parameter with no schema, an unsupported location (e.g. `cookie`), or an optional param
  that is simply absent does **not** by itself make `wasValidated()` true. So a matched GET
  with real query params is never a "skip"; a matched operation that declares nothing
  checkable is.
- **Debug logs skips only.** Successful-validation logging would be noisy and doesn't close
  the diagnostic gap. Skip logs carry **direction** so request vs response skips that share
  a reason (e.g. `missing_spec`, `unmatched_operation`) are distinguishable.

## Architecture

The core (`src/` excluding `src/Drivers/`) stays framework-agnostic.

### Components (dependency order, leaves → roots)

1. **`SkipReason` enum** (core, new) — string-backed (for log/serialization), one case per
   fail-open class named in the issue:
   ```php
   enum SkipReason: string {
       case Unversioned           = 'unversioned';
       case MissingSpec           = 'missing_spec';
       case UnmatchedOperation    = 'unmatched_operation';
       case MissingRequestSchema  = 'missing_request_schema';
       case MissingResponseSchema = 'missing_response_schema';
       case UnsupportedMediaType  = 'unsupported_media_type';
   }
   ```

2. **`ValidationResult`** (core) — gains skip metadata, fully backward compatible:
   - New trailing `public readonly ?SkipReason $skipReason = null`.
   - New static `ValidationResult::skipped(SkipReason $reason, string $version): self`
     → `valid=true`, `errors=[]`, `skipReason=$reason`.
   - `valid()` / `invalid()` unchanged → `skipReason` stays `null`.
   - `wasValidated(): bool` ⇒ `$this->skipReason === null` (true for a pass **and** a genuine
     fail). `wasSkipped(): bool` ⇒ `$this->skipReason !== null` (skips always have
     `valid=true`).

3. **`ContractValidator`** (core):
   - New **trailing** constructor flag `bool $debug = false` — placed **after** the existing
     `?FailureMode $responseFailureMode` param (the last one added in #5).
   - Private helper `skip(SkipReason $reason, string $version, ServerRequestInterface $request,
     Direction $direction): ValidationResult` — centralizes construction and the debug log.
     When `$debug` is true it calls `$this->logger->debug('Contract validation skipped', [
     'version' => $version, 'method' => strtoupper($request->getMethod()),
     'path' => $request->getUri()->getPath(), 'direction' => $direction->value,
     'reason' => $reason->value])`. `skipReason` is set on the result **regardless** of
     `$debug`; only the log is gated.
   - `validateParameters(...)` returns `array{0: string[], 1: int}` — the existing error list
     plus an **evaluated count**: incremented once per schema-backed query/path/header
     parameter that was either value-validated (present) or required-and-missing. Params with
     no `Schema`, unsupported `in` locations, or optional-and-absent do not increment it.

   **Request flow (`validateRequest`):**
   ```
   version null            → skip(Unversioned, 'unversioned', req, Request)
   spec null               → skip(MissingSpec, version, req, Request)
   no path/operation match → skip(UnmatchedOperation, version, req, Request)
   [paramErrors, evaluated] = validateParameters(...)
   determine body schema:
     requestBody === null                         → bodySkip = MissingRequestSchema
     content-type not in requestBody.content      → bodySkip = UnsupportedMediaType
     mediaType present but schema === null        → bodySkip = MissingRequestSchema
     else                                         → bodySchema = schema
   validatedSomething = (evaluated > 0) || (bodySchema !== null)
   if !validatedSomething → skip(bodySkip, version, req, Request)
   errors = paramErrors ∪ (bodySchema ? validateJsonBody : [])
   return errors ? invalid(errors, version) : valid(version)
   ```

   **Response flow (`validateResponse`):**
   ```
   version null                  → skip(Unversioned, 'unversioned', req, Response)
   spec null                     → skip(MissingSpec, version, req, Response)
   operation === null            → skip(UnmatchedOperation, version, req, Response)
   operation->responses === null → skip(MissingResponseSchema, version, req, Response)
   no response object for status (and no 'default') → skip(MissingResponseSchema, …, Response)
   content-type not in specResponse.content         → skip(UnsupportedMediaType, …, Response)
   mediaType present but schema === null            → skip(MissingResponseSchema, …, Response)
   else validateJsonBody → valid/invalid
   ```

   **Media-type classification (applies to both directions):**
   - declares content, but the actual `Content-Type` is not among the declared media types →
     `UnsupportedMediaType`
   - a declared media type exists but has no `schema` →
     `MissingRequestSchema` / `MissingResponseSchema`
   - no request body / no response schema object at all → the corresponding missing-schema
     reason

4. **Laravel config `accord.php`** — add `'debug' => (bool) env('ACCORD_DEBUG', false)`,
   documented (per our knob convention) with purpose **and** tradeoff: when on, Accord logs
   at `debug` level every request/response it skipped and why, so you can tell whether
   validation actually ran; off by default with zero overhead. (Laravel's `env()` already
   returns a real bool for `"true"`/`"false"`.)

5. **`AccordServiceProvider`** — pass `debug: (bool) config('accord.debug', false)` to
   `ContractValidator`.

6. **`AccordFactory`** (Slim/Mezzio, plain array config) — pass
   `debug: filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN)`. **Do not** use a
   raw `(bool)` cast here: array config may carry string inputs like `'false'`, and
   `(bool) 'false' === true`. `FILTER_VALIDATE_BOOLEAN` maps `'false'`/`'0'`/`'no'`/`''` → false.
   - **Also accept an optional `logger` config key** (a `Psr\Log\LoggerInterface`
     instance), passed through to `ContractValidator` only when it is a `LoggerInterface`.
     Without it, the factory-built validator keeps its default `NullLogger`, so `debug =>
     true` would log nowhere. Wiring the logger here is what makes `debug` observable for
     Slim/Mezzio (the Laravel provider already injects a real logger). The README must state
     that factory `debug` requires a `logger` to produce output.

7. **`AssertsApiContracts` trait** (Laravel testing) — add
   `assertResponseWasValidated(TestResponse $response): void`: resolves the validator,
   validates the response, and asserts `$result->wasValidated()`, failing with the skip
   reason in the message when the response was silently skipped (e.g. missing spec, unmatched
   route). Closes the "ran it, saw no violations, because nothing validated" trap. The
   existing `assertResponseMatchesContract` is unchanged.

8. **README / docs** — document `ACCORD_DEBUG`/`debug` (purpose + tradeoff), the
   `wasValidated()`/`wasSkipped()` result helpers, `assertResponseWasValidated()`, and the
   `AccordFactory` `logger` key (note that factory `debug` produces no output without it).

## Data flow (debug on, request to a path with no spec)

```
GET /v2/x → validateRequest → load(v2) === null
  → skip(MissingSpec, 'v2', req, Request)
      → logger.debug("Contract validation skipped", {version:v2, method:GET,
                      path:/v2/x, direction:request, reason:missing_spec})
      → ValidationResult{valid:true, skipReason:MissingSpec}
  → middleware: !valid is false → fail-open preserved, request proceeds
  → in a test: assertResponseWasValidated($resp) FAILS with "skipped: missing_spec"
```

## Error handling / backward compatibility

- Skips keep `valid=true`; both middlewares gate on `if (!$result->valid)`, so runtime
  behavior is **identical** to today. No skip ever throws or renders.
- New `ValidationResult` param and `ContractValidator` `$debug` param are optional with
  defaults reproducing current behavior. `valid()`/`invalid()` signatures unchanged.
- `validateParameters` changes its return shape, but it is `private` — no public API impact.
- `debug=false` performs no logging (no new log volume for existing users).

## Testing (TDD, one behavior per test)

Core:
- `SkipReason` backing values.
- `ValidationResult::skipped(...)` → `valid=true`, `errors=[]`, `wasValidated()===false`,
  `wasSkipped()===true`; `valid()`/`invalid()` → `wasValidated()===true`.
- `ContractValidator` skip reasons, one test each:
  - unversioned request/response → `Unversioned`
  - missing spec → `MissingSpec`
  - unmatched path / unmatched method → `UnmatchedOperation`
  - matched operation, no params + no requestBody → `MissingRequestSchema`
  - request body content-type undeclared (no params) → `UnsupportedMediaType`
  - requestBody media type present but schema null (no params) → `MissingRequestSchema`
  - **matched GET with a real query param → `wasValidated()===true`** (params count)
  - param declared without schema / `cookie` location only → still a skip (does not count)
  - response: unmatched method/path → `UnmatchedOperation`
  - response: operation matched but `responses === null` → `MissingResponseSchema`
  - response: no response object for status → `MissingResponseSchema`
  - response content-type undeclared → `UnsupportedMediaType`
  - genuine pass and genuine fail → `wasValidated()===true`, `skipReason===null`
- Debug logging: `debug=true` + a skip → one `debug`-level record with
  `{version, method, path, direction, reason}` (assert `direction` distinguishes a request
  skip from a response skip with the same reason); `debug=false` → no record.

Factory / Laravel driver:
- Provider passes `debug` (resolve validator, reflect or behavior-assert a skip logs).
- `AccordFactory` with `'debug' => 'false'` (string) → debug stays **off** (FILTER_VALIDATE_BOOLEAN).
- `AccordFactory` with a `'logger'` key + `'debug' => true` → a skip emits a `debug` record on
  the supplied logger; without the `logger` key, `debug => true` produces no output (NullLogger).
- `assertResponseWasValidated` fails on a skipped response (missing spec / unmatched) and
  passes on a validated one.

Whole suite stays green (currently 89) with no new deprecations.

## Out of scope (YAGNI)

- Strict enforcement (turning skips into violations) — separate follow-up issue.
- Logging successful validations.
- Surfacing a *partial* skip (e.g. body media-type undeclared while params validated) on the
  result — when params count, the result is a normal pass/fail and the body skip is not
  separately reported.
- Per-reason configuration.
