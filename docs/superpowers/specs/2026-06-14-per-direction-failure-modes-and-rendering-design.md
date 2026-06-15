# Per-direction failure modes (#5) + HTTP-aware rendering (#6)

**Date:** 2026-06-14
**Issues:** #5 (per-direction failure modes), #6 (HTTP-aware rendering)
**Package state:** accord is released at v1.0.0 — backward compatibility is a hard constraint.

## Problem

1. `ContractValidator::handleFailure(ValidationResult)` applies a single `FailureMode`
   regardless of whether the request or the response violated the contract. A common
   production posture — reject bad requests, only log bad responses — is impossible.
2. `ContractViolationException` is a plain `RuntimeException` with no HTTP awareness, so
   in Laravel a request violation surfaces as a 500. Clients should see a 4xx for their
   own bad request; a bad *response* is a server bug and must not masquerade as a 4xx.

## Decisions (confirmed with user)

- **Config shape:** `failure_mode` accepts a **string OR an array**
  `['request' => ..., 'response' => ...]`. Scalar continues to apply to both directions.
- **Request-violation status:** **configurable, default 422** via
  `request_violation_status` (env `ACCORD_REQUEST_VIOLATION_STATUS`).
- **Response-direction violations:** **never rendered as a client error.** Under
  exception mode a response violation propagates (Laravel 500); under log mode it is
  logged and the original response passes through.
- **Response body shape:** flat `{ "message": string, "errors": string[] }` (accord's
  native error shape), aligned with Laravel's 422 `message`/`errors` convention.
- **Status stays out of the core exception:** the core exception carries only
  `direction`; the Laravel driver maps direction → status. Core stays framework-agnostic.

## Architecture

The core (`src/` excluding `src/Drivers/`) must not depend on any framework. Rendering
is therefore Laravel-driver-only; the core exposes direction so any driver can render.

### Components (dependency order, leaves → roots)

1. **`Direction` enum** (core, new) — a **string-backed** enum (the spec relies on
   `$direction->value` for log context and tests assert `'response'`):
   ```php
   enum Direction: string
   {
       case Request  = 'request';
       case Response = 'response';
   }
   ```
   Leaf; everything hangs off it.

2. **`FailureMode::resolvePair(string|array): array{0: FailureMode, 1: FailureMode}`**
   (core) — single source of truth for the union shape. Used by both `AccordFactory`
   and the Laravel provider so parsing never diverges.
   - `'exception'` → `[Exception, Exception]`
   - `['request'=>'exception','response'=>'log']` → `[Exception, Log]`
   - `['request'=>'exception']` → `[Exception, Exception]` (response key **missing** → falls back to request)
   - `['response'=>'log']` → `[Exception, Log]` (request key **missing** → falls back to the
     default `Exception`; response uses its explicit value)
   - **Fallback vs invalid is distinct:** a *missing* array key falls back. A key that is
     *present but holds an unknown string* still throws `ValueError` via `FailureMode::from`
     — we never silently swallow a typo'd mode. A scalar that is an unknown string likewise
     throws.
   - **Empty/absent precisely:** `null`/unset config and `[]` (empty array — both keys
     missing) fall back to `Exception`. An **explicit empty scalar string `''`** (e.g.
     `ACCORD_FAILURE_MODE=` set-but-blank, which Laravel's `env()` returns as `''` rather
     than the default) is treated as any other invalid mode and **throws** `ValueError`. We
     do not silently tolerate blank env values — a blank mode is a misconfiguration, not a
     request to use the default.

3. **`ContractViolationException`** (core) — add `public readonly Direction $direction`
   (default `Request`). No HTTP status. **Preserve the full existing constructor ABI:**
   the current signature is
   `(ValidationResult $result, string $message = '', int $code = 0, ?Throwable $previous = null)`.
   `Direction` is added as a **trailing** parameter
   (`..., ?Throwable $previous = null, Direction $direction = Direction::Request`), so an
   existing positional call like `new ContractViolationException($result, 'msg', 0, $prev)`
   keeps working unchanged. The validator constructs with the named arg `direction: $direction`.

4. **`ContractValidator`** (core):
   - Constructor gains one optional trailing param `?FailureMode $responseFailureMode = null`.
     `failureMode` remains the request mode and the fallback.
   - `handleFailure(ValidationResult $result, Direction $direction = Direction::Request)`.
     Response mode = `responseFailureMode ?? failureMode`. Throws
     `new ContractViolationException($result, direction: $direction)` in exception mode
     (named arg, since `direction` is now the trailing constructor parameter).
   - **Log context includes direction:** the `Log` branch adds
     `'direction' => $direction->value` to the existing `version`/`errors` context. Under a
     scalar `log` config both directions log, so this is the only way to tell them apart.
     Non-breaking — purely additive context.
   - **Backward compatibility:** with a scalar config, response mode == request mode, so
     behavior is identical to today.

5. **`AccordMiddleware`** (core, PSR-15) — pass `Direction::Request` /
   `Direction::Response` to `handleFailure`. No rendering (Slim/Mezzio have their own
   error handlers, which may read `$e->direction`).

6. **Laravel config `accord.php`** — document `failure_mode` as string-or-array; default
   stays scalar `env('ACCORD_FAILURE_MODE','exception')`. Add
   `request_violation_status => (int) env('ACCORD_REQUEST_VIOLATION_STATUS', 422)`.
   **Cast at the config boundary:** `env()` yields strings and the codebase runs under
   `strict_types=1`, so the value is cast to `int` here (and again defensively in the
   provider). **Range policy:** the provider validates the resolved status is a 4xx
   (`>= 400 && <= 499`); anything outside that range falls back to `422`. Rationale: a
   request-violation response must be a client error — a typo'd `200`/`500` must not be
   able to mislabel a bad request as success or a server fault.

7. **`AccordServiceProvider`** — resolve modes via `FailureMode::resolvePair(config('accord.failure_mode'))`,
   pass both to `ContractValidator`. Resolve the status with `(int) config('accord.request_violation_status', 422)`,
   apply the 4xx range guard (else `422`), and **explicitly bind `ValidateApiContract`**
   in the container with that status — e.g.
   `$this->app->singleton(ValidateApiContract::class, fn () => new ValidateApiContract($validator, $status))`.
   Without an explicit binding, Laravel autowires the middleware for route resolution and,
   because `requestViolationStatus` is a primitive with a default, silently uses `422` and
   ignores config. The binding is what makes the config override actually take effect.

8. **`ValidateApiContract`** (Laravel middleware):
   ```
   request invalid  → handleFailure(result, Request)
                        catch ContractViolationException → JsonResponse({message, errors}, status)
   response invalid → handleFailure(result, Response)
                        exception mode → propagates → Laravel 500 (not caught)
                        log mode       → logged, original response returned unchanged
   ```
   Constructor gains `int $requestViolationStatus = 422`; the value is supplied by the
   provider's explicit container binding (component 7), not by autowiring.

9. **`AccordFactory`** — replace `FailureMode::from($config['failure_mode'])` (line 33,
   breaks on array) with `FailureMode::resolvePair(...)`; pass both modes through.

10. **README** — document the array config form, `request_violation_status`, and the
    request-vs-response rendering behavior.

## Data flow (Laravel, prod posture: request=exception, response=log)

```
HTTP request
  → ValidateApiContract::handle
    → validateRequest → invalid → handleFailure(Request) → throws → caught → 422 JSON  (short-circuit)
    → valid → $next($request) → controller → response
      → validateResponse → invalid → handleFailure(Response) → log mode → warning logged
      → original response returned to client unchanged
```

## Error handling

- Request violation, exception mode: `ContractViolationException(direction=Request)` caught
  in the Laravel middleware → JSON at `request_violation_status`.
- Response violation, exception mode: `ContractViolationException(direction=Response)` NOT
  caught → Laravel renders 500. Correct: a contract-violating response must not be sent.
- Callable mode: unchanged; `failureCallable` invoked for either direction (the callable
  may inspect `result` but not direction in this iteration — YAGNI until needed).
- Fail-open semantics elsewhere (missing spec, unmatched path, etc.) are unchanged.

## Testing (TDD, one behavior per test)

Core (`tests/Feature` / `tests/Unit`):
- `resolvePair`: scalar → both; full array; `['request'=>...]` (response key missing → falls
  back to request); `['response'=>...]` (request key missing → falls back to default Exception);
  array with a present-but-unknown mode string → throws `ValueError`; scalar unknown → throws.
- `handleFailure(Request)` uses request mode; `handleFailure(Response)` uses response mode
  (e.g. request=exception throws, response=log logs — assert via RecordingLogger).
- Log context carries `direction` (assert `'direction' => 'response'` in the logged context).
- Scalar config: response direction still uses the single mode (backward-compat lock).
- `ContractViolationException`: carries the supplied `direction`; and the legacy positional
  call `new ContractViolationException($result, 'msg', 0, $prev)` still constructs (ABI lock).

Laravel driver:
- Request violation under exception → 422 JSON with `message` + flat `errors[]`.
- `request_violation_status` override respected — and a **string** env value (e.g. `"418"`)
  is cast and applied; an out-of-4xx value (e.g. `"500"`/`"200"`) falls back to `422`.
- Provider **binding** is what carries the status: resolving `ValidateApiContract` from the
  container yields the configured status (guards against the autowire-ignores-config trap).
- Response violation under exception → exception propagates (assert thrown).
- Response violation under log → logged, original response returned, status unchanged.
- Provider parses array config and scalar config into the right modes.

All tests must keep the suite green (currently 60 tests) with no new deprecations.

**Test dependency note (for the implementation plan):** the existing Tier-1 provider test
(`LaravelServiceProviderTest`) avoids a real Illuminate dependency by defining local stubs
(a `ServiceProvider` stub, `config()`/`base_path()` functions, a fake container, a
`RecordingLogger`). The new `ValidateApiContract` rendering tests need a real
`Illuminate\Http\Request` and assert on a real `JsonResponse` (status + JSON body) through
the Symfony PSR bridge — stubbing those faithfully is more effort than it's worth and risks
testing the stub instead of the behavior. **Recommended:** add `illuminate/http` (matching
the framework support range) to `require-dev` and write these as real integration tests,
consistent with the project rule to prefer real dependency chains over mocks. The provider
mode-resolution tests can continue to use the lightweight container/config stubs. Final
choice (dev dep vs stubs) is settled in the implementation plan.

## Out of scope (YAGNI)

- Per-direction *callables* (single callable still used for both).
- Configurable response body shape / message text.
- Rendering for Slim/Mezzio (drivers expose `direction`; their error handlers decide).
- Header/cookie-based content negotiation for the error response (always JSON).
