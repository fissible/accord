# Runtime validation options: exclusions, response sampling, response toggle (#8)

**Date:** 2026-06-15
**Issue:** #8 (route exclusions, response sampling, response-validation toggle)
**Package state:** accord is at v1.2.0. This is additive and backward compatible.

## Problem

Runtime response validation applies to every schema-backed response, and there is no
package-level way to exclude routes, sample responses, or turn off response validation
while keeping request validation. Large paginated responses and high-volume endpoints pay
avoidable overhead, which discourages turning the middleware on in production.

## Decisions (confirmed with user)

- **Knobs live in the validator as early skips**, via a new framework-agnostic
  `RuntimeOptions` collaborator. Excluded / response-disabled / sampled-out become
  first-class `SkipReason`s, so #9's `ACCORD_DEBUG` logging and `wasValidated()` report them
  automatically and identically across Laravel/Slim/Mezzio. **The middlewares are unchanged**
  (a skip is `valid=true`, so the existing `if (!$result->valid)` already passes traffic
  through).
- **Exclusions use path glob patterns** where `*` matches any characters **including `/`**
  (so `/v2/internal/*` matches `/v2/internal/a/b/c`, and `*/metrics` matches any version).
  No `**`; segment-bounded matching is intentionally out of scope (more surprising without
  `**`).
- **Gates run early — before spec load/parse/match** — because the entire point is to save
  work. Consequence: skip reasons report **the first runtime gate that prevented
  validation, not a full analysis of all other reasons validation might later have
  skipped**. (E.g. with response validation disabled, every response reports
  `response_validation_disabled` even if it also had no matching schema.)
- **Exclusions apply to both directions** (request and response). **Toggle and sampling are
  response-only** (responses are the expensive, large-payload side).

## Architecture

The core (`src/` excluding `src/Drivers/`) stays framework-agnostic.

### Components (dependency order, leaves → roots)

1. **`SkipReason` — three new cases** (additive; nothing does an exhaustive `match` on it):
   ```php
   case Excluded                   = 'excluded';
   case ResponseValidationDisabled = 'response_validation_disabled';
   case NotSampled                 = 'not_sampled';
   ```

2. **`RuntimeOptions` (core, new value object)** — holds the policy with permissive defaults
   (= current behavior). A single, focused, testable unit:
   ```php
   final class RuntimeOptions
   {
       private readonly float $responseSampleRate; // clamped to [0.0, 1.0]
       /** @var \Closure(): float */
       private readonly \Closure $sampler;

       /** @param string[] $excludedPaths glob patterns */
       public function __construct(
           private readonly array $excludedPaths = [],
           private readonly bool $validateResponses = true,
           float $responseSampleRate = 1.0,
           ?\Closure $sampler = null,
       ) {
           // Range policy: clamp, never throw — a typo'd 2 becomes 1.0, -1 becomes 0.0.
           $this->responseSampleRate = max(0.0, min(1.0, $responseSampleRate));
           // Default draw source; injectable for deterministic tests. Returns a float in [0,1].
           $this->sampler = $sampler ?? static fn (): float => mt_rand() / mt_getrandmax();
       }

       public function isExcluded(string $path): bool
       {
           foreach ($this->excludedPaths as $pattern) {
               $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
               if (preg_match($regex, $path) === 1) {
                   return true;
               }
           }
           return false;
       }

       public function validatesResponses(): bool
       {
           return $this->validateResponses;
       }

       public function shouldSampleResponse(): bool
       {
           if ($this->responseSampleRate >= 1.0) {
               return true;   // always validate (no draw; avoids the 1.0 < 1.0 edge)
           }
           if ($this->responseSampleRate <= 0.0) {
               return false;  // never validate
           }
           return ($this->sampler)() < $this->responseSampleRate;
       }
   }
   ```
   - **Sampler is a pure draw source** returning a float in `[0,1]`. `shouldSampleResponse()`
     is then pure given the (clamped) rate and the draw: inject `fn () => 0.3` against rate
     `0.5` → `true`; `fn () => 0.7` → `false`. The sampler is a **test seam only** — not
     user-configurable from config.
   - **Rate clamping** happens once at construction; `shouldSampleResponse()` never sees an
     out-of-range value.

3. **`ContractValidator` — early gates.** New **trailing** constructor param, placed AFTER
   #9's `bool $debug` (keep it last to avoid ABI churn):
   ```php
   public function __construct(
       private readonly VersionExtractor $versionExtractor,
       private readonly SpecSourceInterface $specSource,
       private readonly FailureMode $failureMode = FailureMode::Exception,
       private readonly mixed $failureCallable = null,
       private readonly LoggerInterface $logger = new NullLogger(),
       private readonly ?FailureMode $responseFailureMode = null,
       private readonly bool $debug = false,
       private readonly RuntimeOptions $runtimeOptions = new RuntimeOptions(),
   ) {}
   ```
   - `validateRequest`: extract `$version` and `$path` up front, then
     **`if ($this->runtimeOptions->isExcluded($path)) return $this->skip(SkipReason::Excluded,
     $version ?? 'unversioned', $request, Direction::Request);`** — before the spec is loaded.
     Then the existing flow unchanged.
   - `validateResponse`: extract `$version` and `$path` up front, then in this order, all
     before spec load:
     1. `isExcluded($path)` → `skip(Excluded, …, Direction::Response)`
     2. `!validatesResponses()` → `skip(ResponseValidationDisabled, …, Direction::Response)`
     3. `!shouldSampleResponse()` → `skip(NotSampled, …, Direction::Response)`
     Then the existing flow unchanged.
   - The `$version ?? 'unversioned'` fallback is used for the gate skips because exclusion can
     match unversioned paths (e.g. `/health`).

4. **Laravel config `accord.php`** — three keys, each documented with purpose **and** cost
   (knob convention):
   - `'exclude' => []` — array of glob patterns; matched routes skip validation entirely
     (both directions). Cost: those routes are *not* contract-checked at all.
   - `'validate_responses' => env('ACCORD_VALIDATE_RESPONSES', true)` — when false, responses
     are not validated (requests still are). Cost: response drift goes uncaught at runtime.
   - `'response_sample_rate' => env('ACCORD_RESPONSE_SAMPLE_RATE', 1.0)` — fraction `0.0–1.0`
     of responses to validate; trades coverage for throughput on hot/large endpoints.
     Out-of-range values are clamped (`2 → 1.0`, `-1 → 0.0`).

5. **`AccordServiceProvider`** — build a `RuntimeOptions` from the three config values and
   pass it via the `runtimeOptions:` named arg. `validate_responses` via `(bool)`,
   `response_sample_rate` via `(float)`, `exclude` as the array (default `[]`).

6. **`AccordFactory`** (Slim/Mezzio, plain array config) — same three keys; build
   `RuntimeOptions` with `validate_responses` via `filter_var(... FILTER_VALIDATE_BOOLEAN)`
   (string-safe, like `debug`), `response_sample_rate` via `(float)`, `exclude` as array.
   Docblock updated to list the three keys.

7. **README** — a "Running it safely in production" section: exclude health/metrics/internal
   routes, sample responses on hot endpoints, or disable response validation — each with the
   *why/cost*. **Explicit warning:** with `validate_responses=false` (or a very low sample
   rate) AND `ACCORD_DEBUG=true`, debug logs every response as a skip
   (`response_validation_disabled` / `not_sampled`) — that's expected, since debug is a
   diagnostic mode, but it is noisy; don't run that combination in steady state.

## Data flow (Laravel; `/v2/health` excluded, debug on)

```
GET /v2/health
  → validateRequest → isExcluded('/v2/health') → skip(Excluded, request)
       → ACCORD_DEBUG logs {direction:request, reason:excluded}
       → valid=true → middleware passes request through (no spec loaded)
  → $next → controller → response
  → validateResponse → isExcluded('/v2/health') → skip(Excluded, response)
       → logs {direction:response, reason:excluded}
       → valid=true → original response returned unvalidated
```

## Error handling / backward compatibility

- Gate skips are `valid=true`; both middlewares (`if (!$result->valid)`) behave exactly as
  today — excluded/disabled/sampled-out traffic passes through unvalidated, nothing throws.
- `RuntimeOptions` defaults are permissive (`[]`, `true`, `1.0`) → identical behavior for
  any existing caller that doesn't pass it.
- New `SkipReason` cases are additive; the trailing `ContractValidator` ctor param is
  optional with a `new RuntimeOptions()` default. No public signature breaks.

## Testing (TDD, one behavior per test)

`RuntimeOptions` (unit):
- `isExcluded`: exact match; `*` crosses `/` (`/v2/internal/*` matches `/v2/internal/a/b`);
  `*/metrics` matches `/v9/metrics`; non-match returns false; empty list → false.
- rate clamping: `2.0 → 1.0` (always), `-1.0 → 0.0` (never).
- `shouldSampleResponse`: rate `1.0` → true without consulting sampler; rate `0.0` → false;
  rate `0.5` with injected draw `0.3` → true, draw `0.7` → false.
- `validatesResponses` reflects the flag.

`ContractValidator` (feature, against the v2 fixture):
- excluded request → `skip(Excluded)`, `wasValidated()===false`.
- excluded response → `skip(Excluded)`.
- `validateResponses=false` → response → `ResponseValidationDisabled` (and a normally-valid
  response is NOT validated).
- sample rate with injected draw above the rate → `NotSampled`; draw below → normal
  validation occurs (genuine pass/fail).
- exclusion precedence: an excluded path that is also unversioned → `Excluded` (not
  `Unversioned`).
- gates short-circuit before spec load: an excluded request to a version whose spec file is
  absent still returns `Excluded` (not `MissingSpec`).
- debug on: an excluded skip logs `reason: excluded` with the right direction.

Laravel driver / factory:
- Provider builds `RuntimeOptions` from config (resolve validator; exclude a path; assert the
  result is `Excluded`).
- `AccordFactory` with `'validate_responses' => 'false'` (string) disables response validation
  (FILTER_VALIDATE_BOOLEAN); with `'exclude' => [...]` excludes; with `response_sample_rate`
  cast to float.

Whole suite stays green (currently 114) with no new deprecations.

## Out of scope (YAGNI)

- Request sampling (responses are the expensive side).
- Per-route or per-direction sample rates / per-direction exclusions.
- `**` segment-aware globs or regex exclusions.
- User-configurable sampler (the closure is a test seam only).
- Strict enforcement interactions (separate concern).
