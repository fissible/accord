# Runtime Validation Options Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users exclude routes, sample responses, and toggle response validation off — to run Accord's runtime middleware cheaply in production.

**Architecture:** A new framework-agnostic `RuntimeOptions` value object holds the policy; `ContractValidator` consults it as *early skips* (before loading the spec), returning new `SkipReason`s (`Excluded`, `ResponseValidationDisabled`, `NotSampled`). Because a skip is `valid=true`, both middlewares already pass that traffic through unchanged — and #9's `ACCORD_DEBUG`/`wasValidated()` report the skips for free.

**Tech Stack:** PHP 8.2+, PHPUnit 11, cebe/php-openapi, nyholm/psr7, illuminate/http (dev).

**Spec:** `docs/superpowers/specs/2026-06-15-runtime-validation-options-design.md`

**Branch:** `feat/runtime-options` (already checked out).

**Conventions:** `declare(strict_types=1)` everywhere; no public non-readonly props; framework code only under `src/Drivers/`. `SkipReason`, `Direction`, `RuntimeOptions`, `ValidationResult`, `FailureMode` are all in namespace `Fissible\Accord` — code inside `src/*.php` references them with NO `use`. Run the suite with `vendor/bin/phpunit --colors=never`. **Baseline: 114 tests passing, 5 pre-existing cebe deprecations** — the count must stay 5.

---

## File Structure

**Core (create):** `src/RuntimeOptions.php` — the exclude/sample/toggle policy + glob matching + sampling decision.

**Core (modify):**
- `src/SkipReason.php` — add `Excluded`, `ResponseValidationDisabled`, `NotSampled`.
- `src/ContractValidator.php` — trailing `RuntimeOptions $runtimeOptions` ctor param + early gates in `validateRequest`/`validateResponse`.
- `src/AccordFactory.php` — build `RuntimeOptions` from config; docblock.

**Laravel driver (modify):**
- `src/Drivers/Laravel/config/accord.php` — `exclude` / `validate_responses` / `response_sample_rate` (documented).
- `src/Drivers/Laravel/Providers/AccordServiceProvider.php` — build + pass `RuntimeOptions`.

**Tests (create):** `tests/Unit/RuntimeOptionsTest.php`.
**Tests (modify):** `tests/Unit/SkipReasonTest.php`, `tests/Feature/ContractValidatorTest.php`, `tests/Feature/LaravelServiceProviderTest.php`, `tests/Unit/AccordFactoryTest.php`.
**Docs (modify):** `README.md`.

---

## Task 1: `SkipReason` — three runtime-gate reasons

**Files:**
- Modify: `src/SkipReason.php`
- Test: `tests/Unit/SkipReasonTest.php`

The current enum has six cases (`Unversioned` … `UnsupportedMediaType`).

- [ ] **Step 1: Write the failing test**

Add this method to the existing `SkipReasonTest` class in `tests/Unit/SkipReasonTest.php`:

```php
    public function test_runtime_gate_backing_values(): void
    {
        $this->assertSame('excluded', SkipReason::Excluded->value);
        $this->assertSame('response_validation_disabled', SkipReason::ResponseValidationDisabled->value);
        $this->assertSame('not_sampled', SkipReason::NotSampled->value);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SkipReasonTest`
Expected: FAIL — `Undefined constant Fissible\Accord\SkipReason::Excluded`.

- [ ] **Step 3: Add the three cases**

Replace the entire contents of `src/SkipReason.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum SkipReason: string
{
    case Unversioned                = 'unversioned';
    case MissingSpec                = 'missing_spec';
    case UnmatchedOperation         = 'unmatched_operation';
    case MissingRequestSchema       = 'missing_request_schema';
    case MissingResponseSchema      = 'missing_response_schema';
    case UnsupportedMediaType       = 'unsupported_media_type';
    case Excluded                   = 'excluded';
    case ResponseValidationDisabled = 'response_validation_disabled';
    case NotSampled                 = 'not_sampled';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SkipReasonTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/SkipReason.php tests/Unit/SkipReasonTest.php
git commit -m "feat: add runtime-gate skip reasons (excluded/response-disabled/not-sampled) (#8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `RuntimeOptions` value object

**Files:**
- Create: `src/RuntimeOptions.php`
- Test: `tests/Unit/RuntimeOptionsTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/RuntimeOptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\RuntimeOptions;
use PHPUnit\Framework\TestCase;

class RuntimeOptionsTest extends TestCase
{
    public function test_exact_path_is_excluded(): void
    {
        $this->assertTrue((new RuntimeOptions(['/v2/health']))->isExcluded('/v2/health'));
    }

    public function test_non_matching_path_is_not_excluded(): void
    {
        $this->assertFalse((new RuntimeOptions(['/v2/health']))->isExcluded('/v2/users'));
    }

    public function test_empty_exclusions_never_match(): void
    {
        $this->assertFalse((new RuntimeOptions())->isExcluded('/v2/anything'));
    }

    public function test_star_matches_across_slashes(): void
    {
        $options = new RuntimeOptions(['/v2/internal/*']);

        $this->assertTrue($options->isExcluded('/v2/internal/a/b/c'));
        $this->assertFalse($options->isExcluded('/v2/public/x'));
    }

    public function test_leading_star_matches_any_version(): void
    {
        $this->assertTrue((new RuntimeOptions(['*/metrics']))->isExcluded('/v9/metrics'));
    }

    public function test_validates_responses_reflects_flag(): void
    {
        $this->assertTrue((new RuntimeOptions(validateResponses: true))->validatesResponses());
        $this->assertFalse((new RuntimeOptions(validateResponses: false))->validatesResponses());
    }

    public function test_rate_above_one_is_clamped_to_always_sample(): void
    {
        $options = new RuntimeOptions(
            responseSampleRate: 2.0,
            sampler: fn (): float => throw new \RuntimeException('sampler must not be consulted at rate 1.0'),
        );

        $this->assertTrue($options->shouldSampleResponse());
    }

    public function test_rate_below_zero_is_clamped_to_never_sample(): void
    {
        $options = new RuntimeOptions(
            responseSampleRate: -1.0,
            sampler: fn (): float => throw new \RuntimeException('sampler must not be consulted at rate 0.0'),
        );

        $this->assertFalse($options->shouldSampleResponse());
    }

    public function test_draw_below_rate_is_sampled_in(): void
    {
        $options = new RuntimeOptions(responseSampleRate: 0.5, sampler: fn (): float => 0.3);

        $this->assertTrue($options->shouldSampleResponse());
    }

    public function test_draw_at_or_above_rate_is_sampled_out(): void
    {
        $options = new RuntimeOptions(responseSampleRate: 0.5, sampler: fn (): float => 0.7);

        $this->assertFalse($options->shouldSampleResponse());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter RuntimeOptionsTest`
Expected: FAIL — `Class "Fissible\Accord\RuntimeOptions" not found`.

- [ ] **Step 3: Write the value object**

Create `src/RuntimeOptions.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord;

use Closure;

/**
 * Runtime validation policy: which routes to skip, whether to validate responses,
 * and what fraction of responses to sample. Permissive defaults reproduce
 * "validate everything" behavior.
 */
final class RuntimeOptions
{
    private readonly float $responseSampleRate;

    /** @var Closure(): float */
    private readonly Closure $sampler;

    /**
     * @param string[]      $excludedPaths      glob patterns; '*' matches any chars including '/'
     * @param bool          $validateResponses  validate responses (requests are always validated unless excluded)
     * @param float         $responseSampleRate fraction 0.0–1.0 of responses to validate (clamped, never throws)
     * @param Closure(): float|null $sampler    test seam: a draw source returning a float in [0,1]
     */
    public function __construct(
        private readonly array $excludedPaths = [],
        private readonly bool $validateResponses = true,
        float $responseSampleRate = 1.0,
        ?Closure $sampler = null,
    ) {
        $this->responseSampleRate = max(0.0, min(1.0, $responseSampleRate));
        $this->sampler            = $sampler ?? static fn (): float => mt_rand() / mt_getrandmax();
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
            return true;
        }

        if ($this->responseSampleRate <= 0.0) {
            return false;
        }

        return ($this->sampler)() < $this->responseSampleRate;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter RuntimeOptionsTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add src/RuntimeOptions.php tests/Unit/RuntimeOptionsTest.php
git commit -m "feat: add RuntimeOptions (glob exclusions, response toggle, sampling) (#8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `ContractValidator` — early runtime gates

**Files:**
- Modify: `src/ContractValidator.php`
- Test: `tests/Feature/ContractValidatorTest.php`

This reuses the `tests/Fixtures/v2.yaml` fixture already created in #9 (`/v2/items` GET returns a `200` array; etc.).

- [ ] **Step 1: Write the failing tests**

Add `use Fissible\Accord\RuntimeOptions;` to the imports of `tests/Feature/ContractValidatorTest.php` (it already imports `SkipReason`, `Direction`, `RecordingLogger`, `ContractValidator`, `FileSpecSource`, `Response`, `ServerRequest`, etc.). Add this helper and the tests inside the class:

```php
    private function makeOptionsValidator(
        RuntimeOptions $options,
        ?RecordingLogger $logger = null,
        bool $debug = false,
    ): ContractValidator {
        return new ContractValidator(
            versionExtractor: $this->versionExtractor,
            specSource:       new FileSpecSource($this->fixturesPath, '{base}/{version}'),
            logger:           $logger ?? new RecordingLogger(),
            debug:            $debug,
            runtimeOptions:   $options,
        );
    }

    // --- runtime options: exclusions / toggle / sampling (#8) ---

    public function test_excluded_request_is_skipped(): void
    {
        $validator = $this->makeOptionsValidator(new RuntimeOptions(['/v2/items']));

        $result = $validator->validateRequest(new ServerRequest('GET', '/v2/items'));

        $this->assertSame(SkipReason::Excluded, $result->skipReason);
        $this->assertFalse($result->wasValidated());
    }

    public function test_excluded_response_is_skipped(): void
    {
        $validator = $this->makeOptionsValidator(new RuntimeOptions(['/v2/items']));

        $result = $validator->validateResponse(
            $this->jsonResponse(200, '[]'),
            new ServerRequest('GET', '/v2/items'),
        );

        $this->assertSame(SkipReason::Excluded, $result->skipReason);
    }

    public function test_exclusion_takes_precedence_over_unversioned(): void
    {
        $validator = $this->makeOptionsValidator(new RuntimeOptions(['/health']));

        $result = $validator->validateRequest(new ServerRequest('GET', '/health'));

        $this->assertSame(SkipReason::Excluded, $result->skipReason);
    }

    public function test_exclusion_short_circuits_before_spec_load(): void
    {
        // /v99 has no spec; exclusion must win (Excluded, not MissingSpec).
        $validator = $this->makeOptionsValidator(new RuntimeOptions(['/v99/*']));

        $result = $validator->validateRequest(new ServerRequest('GET', '/v99/items'));

        $this->assertSame(SkipReason::Excluded, $result->skipReason);
    }

    public function test_response_validation_disabled_skips_response(): void
    {
        $validator = $this->makeOptionsValidator(new RuntimeOptions(validateResponses: false));

        // A response that would otherwise validate (GET /v2/items 200 array).
        $result = $validator->validateResponse(
            $this->jsonResponse(200, '[]'),
            new ServerRequest('GET', '/v2/items'),
        );

        $this->assertSame(SkipReason::ResponseValidationDisabled, $result->skipReason);
    }

    public function test_request_still_validated_when_responses_disabled(): void
    {
        $validator = $this->makeOptionsValidator(new RuntimeOptions(validateResponses: false));

        // GET /v2/items/5 has an evaluated path param → request is validated regardless.
        $result = $validator->validateRequest(new ServerRequest('GET', '/v2/items/5'));

        $this->assertTrue($result->wasValidated());
    }

    public function test_response_sampled_out_is_skipped(): void
    {
        $validator = $this->makeOptionsValidator(
            new RuntimeOptions(responseSampleRate: 0.5, sampler: fn (): float => 0.7),
        );

        $result = $validator->validateResponse(
            $this->jsonResponse(200, '[]'),
            new ServerRequest('GET', '/v2/items'),
        );

        $this->assertSame(SkipReason::NotSampled, $result->skipReason);
    }

    public function test_response_sampled_in_is_validated(): void
    {
        $validator = $this->makeOptionsValidator(
            new RuntimeOptions(responseSampleRate: 0.5, sampler: fn (): float => 0.3),
        );

        $result = $validator->validateResponse(
            $this->jsonResponse(200, '[]'),
            new ServerRequest('GET', '/v2/items'),
        );

        $this->assertTrue($result->wasValidated());
        $this->assertTrue($result->valid);
    }

    public function test_debug_logs_excluded_skip(): void
    {
        $logger    = new RecordingLogger();
        $validator = $this->makeOptionsValidator(new RuntimeOptions(['/v2/items']), $logger, debug: true);

        $validator->validateRequest(new ServerRequest('GET', '/v2/items'));

        $this->assertSame('excluded', $logger->records[0]['context']['reason']);
        $this->assertSame('request', $logger->records[0]['context']['direction']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ContractValidatorTest`
Expected: FAIL — `runtimeOptions` is not a known named parameter.

- [ ] **Step 3: Add the ctor param and the gates**

(a) In `src/ContractValidator.php`, add the trailing `runtimeOptions` param (keep the existing seven params unchanged):

```php
    public function __construct(
        private readonly VersionExtractor $versionExtractor,
        private readonly SpecSourceInterface $specSource,
        private readonly FailureMode $failureMode = FailureMode::Exception,
        /** @var callable(ValidationResult): void|null */
        private readonly mixed $failureCallable = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?FailureMode $responseFailureMode = null,
        private readonly bool $debug = false,
        private readonly RuntimeOptions $runtimeOptions = new RuntimeOptions(),
    ) {}
```

(b) Replace the WHOLE `validateRequest` method with (note `$path` now extracted up front, and the exclusion gate added before the version check):

```php
    public function validateRequest(ServerRequestInterface $request): ValidationResult
    {
        $version = $this->versionExtractor->extract($request);
        $path    = $request->getUri()->getPath();

        if ($this->runtimeOptions->isExcluded($path)) {
            return $this->skip(SkipReason::Excluded, $version ?? 'unversioned', $request, Direction::Request);
        }

        if ($version === null) {
            return $this->skip(SkipReason::Unversioned, 'unversioned', $request, Direction::Request);
        }

        $spec = $this->loadSpec($version);

        if ($spec === null) {
            return $this->skip(SkipReason::MissingSpec, $version, $request, Direction::Request);
        }

        $method = strtolower($request->getMethod());
        $match  = $this->findPathItem($spec, $path);

        if ($match === null) {
            return $this->skip(SkipReason::UnmatchedOperation, $version, $request, Direction::Request);
        }

        $operation = $match['pathItem']->getOperations()[$method] ?? null;

        if ($operation === null) {
            return $this->skip(SkipReason::UnmatchedOperation, $version, $request, Direction::Request);
        }

        [$paramErrors, $paramsEvaluated] = $this->validateParameters(
            $operation,
            $match['pathItem'],
            $match['template'],
            $request,
        );

        $bodySchema = null;
        $bodySkip   = SkipReason::MissingRequestSchema;

        if ($operation->requestBody !== null) {
            $contentType = $this->parseContentType($request->getHeaderLine('Content-Type'));
            $mediaType   = $operation->requestBody->content[$contentType] ?? null;

            if ($mediaType === null) {
                $bodySkip = SkipReason::UnsupportedMediaType;
            } elseif ($mediaType->schema === null) {
                $bodySkip = SkipReason::MissingRequestSchema;
            } else {
                $bodySchema = $mediaType->schema;
            }
        }

        if ($paramsEvaluated === 0 && $bodySchema === null) {
            return $this->skip($bodySkip, $version, $request, Direction::Request);
        }

        $errors = $paramErrors;

        if ($bodySchema !== null) {
            $errors = array_merge($errors, $this->validateJsonBody((string) $request->getBody(), $bodySchema));
        }

        return empty($errors)
            ? ValidationResult::valid($version)
            : ValidationResult::invalid($errors, $version);
    }
```

(c) Replace the WHOLE `validateResponse` method with (exclusion → response-disabled → not-sampled gates added before the version check, all before spec load):

```php
    public function validateResponse(ResponseInterface $response, ServerRequestInterface $request): ValidationResult
    {
        $version  = $this->versionExtractor->extract($request);
        $path     = $request->getUri()->getPath();
        $fallback = $version ?? 'unversioned';

        if ($this->runtimeOptions->isExcluded($path)) {
            return $this->skip(SkipReason::Excluded, $fallback, $request, Direction::Response);
        }

        if (!$this->runtimeOptions->validatesResponses()) {
            return $this->skip(SkipReason::ResponseValidationDisabled, $fallback, $request, Direction::Response);
        }

        if (!$this->runtimeOptions->shouldSampleResponse()) {
            return $this->skip(SkipReason::NotSampled, $fallback, $request, Direction::Response);
        }

        if ($version === null) {
            return $this->skip(SkipReason::Unversioned, 'unversioned', $request, Direction::Response);
        }

        $spec = $this->loadSpec($version);

        if ($spec === null) {
            return $this->skip(SkipReason::MissingSpec, $version, $request, Direction::Response);
        }

        $method    = strtolower($request->getMethod());
        $operation = $this->findOperation($spec, $method, $path);

        if ($operation === null) {
            return $this->skip(SkipReason::UnmatchedOperation, $version, $request, Direction::Response);
        }

        if ($operation->responses === null) {
            return $this->skip(SkipReason::MissingResponseSchema, $version, $request, Direction::Response);
        }

        $statusCode   = (string) $response->getStatusCode();
        $specResponse = $operation->responses->getResponse($statusCode)
            ?? $operation->responses->getResponse('default');

        if ($specResponse === null) {
            return $this->skip(SkipReason::MissingResponseSchema, $version, $request, Direction::Response);
        }

        $contentType = $this->parseContentType($response->getHeaderLine('Content-Type'));
        $mediaType   = $specResponse->content[$contentType] ?? null;

        if ($mediaType === null) {
            return $this->skip(SkipReason::UnsupportedMediaType, $version, $request, Direction::Response);
        }

        if ($mediaType->schema === null) {
            return $this->skip(SkipReason::MissingResponseSchema, $version, $request, Direction::Response);
        }

        $body   = (string) $response->getBody();
        $errors = $this->validateJsonBody($body, $mediaType->schema);

        return empty($errors)
            ? ValidationResult::valid($version)
            : ValidationResult::invalid($errors, $version);
    }
```

`RuntimeOptions`, `SkipReason`, `Direction` are all in the same `Fissible\Accord` namespace — do NOT add `use` statements. Do not change any other method.

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 114 prior + 1 (Task 1) + 10 (Task 2) + 9 (Task 3) = 134 tests. Deprecations remain 5. The existing #9 skip tests still pass (their validators use the permissive default `RuntimeOptions`).

- [ ] **Step 5: Commit**

```bash
git add src/ContractValidator.php tests/Feature/ContractValidatorTest.php
git commit -m "feat: ContractValidator applies runtime gates (exclude/toggle/sample) as early skips (#8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Laravel config — exclude / validate_responses / response_sample_rate

**Files:**
- Modify: `src/Drivers/Laravel/config/accord.php`

No isolated test (config data); exercised by Task 5.

- [ ] **Step 1: Add the three blocks**

In `src/Drivers/Laravel/config/accord.php`, add these blocks immediately before the final closing `];` (after the existing `debug` key):

```php

    /*
    |--------------------------------------------------------------------------
    | Route Exclusions
    |--------------------------------------------------------------------------
    | Glob patterns matched against the request URI path; '*' matches any
    | characters, INCLUDING '/'. Matched routes skip BOTH request and response
    | validation entirely — they are not contract-checked at all. Use only for
    | routes you deliberately don't cover (health checks, metrics, internal
    | endpoints), e.g. ['/v2/health', '/v2/internal/*', '*/metrics'].
    |
    */
    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Validate Responses
    |--------------------------------------------------------------------------
    | When false, responses are not validated at runtime but requests still are.
    | Cost: response/contract drift goes uncaught at runtime — rely on CI
    | (AssertsApiContracts) to catch it instead. Useful for high-volume APIs
    | where response validation overhead isn't worth it in production.
    |
    */
    'validate_responses' => env('ACCORD_VALIDATE_RESPONSES', true),

    /*
    |--------------------------------------------------------------------------
    | Response Sample Rate
    |--------------------------------------------------------------------------
    | Fraction (0.0–1.0) of responses to validate; the rest pass through
    | unchecked. Trades response coverage for throughput on hot or large-payload
    | endpoints. Out-of-range values are clamped (2 -> 1.0, -1 -> 0.0). 1.0
    | validates every response (default).
    |
    */
    'response_sample_rate' => env('ACCORD_RESPONSE_SAMPLE_RATE', 1.0),
```

- [ ] **Step 2: Verify syntax + suite**

Run: `php -l src/Drivers/Laravel/config/accord.php` → "No syntax errors detected".
Run: `vendor/bin/phpunit --colors=never` → 134 tests, 5 deprecations.

- [ ] **Step 3: Commit**

```bash
git add src/Drivers/Laravel/config/accord.php
git commit -m "feat: Laravel config for exclude/validate_responses/response_sample_rate (#8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `AccordServiceProvider` builds + passes `RuntimeOptions`

**Files:**
- Modify: `src/Drivers/Laravel/Providers/AccordServiceProvider.php`
- Test: `tests/Feature/LaravelServiceProviderTest.php`

The current `ContractValidator` singleton closure ends with:

```php
            return new ContractValidator(
                versionExtractor:    $this->app->make(VersionExtractor::class),
                specSource:          $this->app->make(SpecSourceInterface::class),
                failureMode:         $requestMode,
                failureCallable:     $failureCallable,
                logger:              $this->resolveLogger(),
                responseFailureMode: $responseMode,
                debug:               (bool) config('accord.debug', false),
            );
```

- [ ] **Step 1: Write the failing test**

In `tests/Feature/LaravelServiceProviderTest.php`, add these keys to the `LaravelConfig::$values` array in `setUp()` (next to `accord.debug`):

```php
                'accord.exclude'               => [],
                'accord.validate_responses'    => true,
                'accord.response_sample_rate'  => 1.0,
```

Add this method to the class (match the existing 8-space method indentation):

```php
        public function test_exclude_config_skips_validation(): void
        {
            LaravelConfig::$values['accord.exclude'] = ['/v99/x'];

            $app = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);
            (new AccordServiceProvider($app))->register();

            $validator = $app->make(ContractValidator::class);
            $result    = $validator->validateRequest(new ServerRequest('GET', '/v99/x'));

            $this->assertSame(\Fissible\Accord\SkipReason::Excluded, $result->skipReason);
        }
```

(`ServerRequest` was imported in #9's Task 5; `SkipReason` is referenced fully-qualified to avoid touching the import block.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter LaravelServiceProviderTest`
Expected: FAIL — the provider builds a default `RuntimeOptions` (no exclusions), so the request resolves to `MissingSpec`, not `Excluded`.

- [ ] **Step 3: Build RuntimeOptions in the provider**

In `src/Drivers/Laravel/Providers/AccordServiceProvider.php`, add the import near the existing `use` statements:

```php
use Fissible\Accord\RuntimeOptions;
```

In the `ContractValidator` singleton closure, add the `runtimeOptions` named argument after `debug:`:

```php
            return new ContractValidator(
                versionExtractor:    $this->app->make(VersionExtractor::class),
                specSource:          $this->app->make(SpecSourceInterface::class),
                failureMode:         $requestMode,
                failureCallable:     $failureCallable,
                logger:              $this->resolveLogger(),
                responseFailureMode: $responseMode,
                debug:               (bool) config('accord.debug', false),
                runtimeOptions:      new RuntimeOptions(
                    excludedPaths:      config('accord.exclude', []),
                    validateResponses:  (bool) config('accord.validate_responses', true),
                    responseSampleRate: (float) config('accord.response_sample_rate', 1.0),
                ),
            );
```

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 134 prior + 1 = 135 tests. Deprecations 5. Existing provider tests still pass.

- [ ] **Step 5: Commit**

```bash
git add src/Drivers/Laravel/Providers/AccordServiceProvider.php tests/Feature/LaravelServiceProviderTest.php
git commit -m "feat: Laravel provider builds RuntimeOptions from config (#8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `AccordFactory` builds `RuntimeOptions`

**Files:**
- Modify: `src/AccordFactory.php`
- Test: `tests/Unit/AccordFactoryTest.php`

The current factory validator-construction block ends with `debug: filter_var(...)`. `AccordFactory` is in namespace `Fissible\Accord`, so `RuntimeOptions` needs NO `use`.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/AccordFactoryTest.php` (which, from #8 Task references and #9, imports `RecordingLogger`, `Response`, `ServerRequest`, `RequestHandlerInterface`, etc., and has a `passthroughHandler()` helper), add these two tests. If `passthroughHandler()` is NOT present, also add it (see #9's AccordFactoryTest for its exact form: a private method returning an anonymous `RequestHandlerInterface` whose `handle()` returns `new Response(200)`).

```php
    public function test_factory_exclude_skips_validation(): void
    {
        $logger = new RecordingLogger();

        $middleware = AccordFactory::make(
            ['exclude' => ['/v99/x'], 'debug' => true, 'logger' => $logger],
            dirname(__DIR__) . '/Fixtures',
        );

        $middleware->process(new ServerRequest('GET', '/v99/x'), $this->passthroughHandler());

        $reasons = array_column(array_column($logger->records, 'context'), 'reason');
        $this->assertContains('excluded', $reasons);
    }

    public function test_factory_string_false_validate_responses_disables_response_validation(): void
    {
        $logger = new RecordingLogger();

        $middleware = AccordFactory::make(
            ['validate_responses' => 'false', 'debug' => true, 'logger' => $logger], // string must parse to false
            dirname(__DIR__) . '/Fixtures',
        );

        // /v99/x has no spec: request → missing_spec; response → response_validation_disabled
        // (the response gate fires BEFORE spec load; if 'false' were mis-cast to true,
        //  the response side would log missing_spec instead).
        $middleware->process(new ServerRequest('GET', '/v99/x'), $this->passthroughHandler());

        $reasons = array_column(array_column($logger->records, 'context'), 'reason');
        $this->assertContains('response_validation_disabled', $reasons);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter AccordFactoryTest`
Expected: FAIL — the factory builds a default `RuntimeOptions`, so `exclude` is ignored (`excluded` never logged) and `validate_responses` has no effect.

- [ ] **Step 3: Build RuntimeOptions in the factory**

In `src/AccordFactory.php`, replace the `$validator = new ContractValidator(...)` call with (add the `runtimeOptions` arg after `debug:`):

```php
        $validator = new ContractValidator(
            versionExtractor:    $versionExtractor,
            specSource:          $specSource,
            failureMode:         $requestMode,
            failureCallable:     $failureCallable,
            logger:              $logger,
            responseFailureMode: $responseMode,
            debug:               filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN),
            runtimeOptions:      new RuntimeOptions(
                excludedPaths:      $config['exclude'] ?? [],
                validateResponses:  filter_var($config['validate_responses'] ?? true, FILTER_VALIDATE_BOOLEAN),
                responseSampleRate: (float) ($config['response_sample_rate'] ?? 1.0),
            ),
        );
```

Then update the class docblock's "Config keys (all optional)" list to add:

```php
 *   exclude          — string[] of glob patterns; matched routes skip all validation (default: [])
 *   validate_responses — bool; validate responses (requests always validated)  (default: true)
 *   response_sample_rate — float 0.0–1.0; fraction of responses to validate, clamped (default: 1.0)
```

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 135 prior + 2 = 137 tests. Deprecations 5.

- [ ] **Step 5: Commit**

```bash
git add src/AccordFactory.php tests/Unit/AccordFactoryTest.php
git commit -m "feat: AccordFactory builds RuntimeOptions from config (#8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: README — running it safely in production

**Files:**
- Modify: `README.md`

No test (docs).

- [ ] **Step 1: Add config lines**

In `README.md`, find the Laravel published-config snippet (it contains `'debug' => env('ACCORD_DEBUG', false), ...` added in #9). Add, after the `debug` line:

```php
    'exclude'              => [],                                  // glob patterns; matched routes skip all validation
    'validate_responses'   => env('ACCORD_VALIDATE_RESPONSES', true),    // false = don't validate responses (requests still validated)
    'response_sample_rate' => env('ACCORD_RESPONSE_SAMPLE_RATE', 1.0),   // fraction of responses to validate (0.0–1.0)
```

- [ ] **Step 2: Add the production section**

Immediately after that config snippet's closing ``` fence, insert:

```markdown
**Running it in production — controlling overhead.** Response validation runs on every
response by default. Three knobs let you keep it cheap:

- **`exclude`** — glob patterns (`*` matches any characters, including `/`). Matched routes
  skip *all* validation, request and response (e.g. `['/v2/health', '/v2/internal/*',
  '*/metrics']`). Cost: those routes aren't contract-checked at all.
- **`validate_responses => false`** — stop validating responses while still validating
  requests. Cost: response drift goes uncaught at runtime — rely on the
  `AssertsApiContracts` CI checks instead.
- **`response_sample_rate`** — validate only a fraction of responses (e.g. `0.1` ≈ 10%).
  Trades coverage for throughput on hot or large-payload endpoints; out-of-range values are
  clamped to `0.0..1.0`.

These show up in `ACCORD_DEBUG` output as `excluded`, `response_validation_disabled`, and
`not_sampled` skips, and as `wasValidated() === false`. **Note:** with `validate_responses`
off (or a very low sample rate) *and* `ACCORD_DEBUG` on, debug logs *every* response as a
skip — that's expected for a diagnostic mode, but don't run that combination in steady
state. (For the Slim/Mezzio `AccordFactory`, remember debug logging only produces output
when you pass a `logger` config key — the Laravel driver injects one automatically.)
```

- [ ] **Step 3: Verify suite unaffected**

Run: `vendor/bin/phpunit --colors=never` → 137 tests, 5 deprecations.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: document runtime validation options + production adoption (#8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Final verification

- [ ] **Step 1: Full suite + clean tree**

```bash
vendor/bin/phpunit --colors=never
git diff --check
```
Expected: 137 tests pass, 5 deprecations (no new). `git diff --check` prints nothing.

- [ ] **Step 2: Core stayed framework-agnostic**

```bash
grep -rn 'Illuminate\\' src/ | grep -v src/Drivers/
```
Expected: no output.

- [ ] **Step 3: Push + PR (only if the user asks)**

Do not push/PR unless asked. When asked:

```bash
git push -u origin feat/runtime-options
gh pr create --title "feat: runtime validation options — exclusions, sampling, response toggle (#8)" --body "$(cat <<'EOF'
Implements #8. Lets the runtime middleware run cheaply in production.

- `RuntimeOptions` value object: glob route exclusions (`*` crosses `/`), response-validation toggle, response sampling (rate clamped 0.0–1.0; pure, injectable draw source).
- `ContractValidator` applies them as EARLY skips (before spec load) → new `SkipReason`s `excluded` / `response_validation_disabled` / `not_sampled`. Middlewares unchanged (skips are `valid=true`); #9's `ACCORD_DEBUG` + `wasValidated()` report them for free.
- Exclusions apply to request + response; toggle and sampling are response-only.
- Laravel config (`exclude`, `validate_responses`, `response_sample_rate`) + provider wiring; `AccordFactory` builds RuntimeOptions (bool-safe + float-cast).
- README "running it in production" section with per-knob cost + the debug-noise caveat.

Backward compatible: permissive defaults reproduce current behavior; new ctor param trailing; new skip reasons additive.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes (author)

- **Spec coverage:** SkipReason +3 (T1), RuntimeOptions glob/toggle/sampling/clamp (T2), validator early gates + precedence + short-circuit-before-spec (T3), config knobs documented (T4), provider wiring (T5), factory bool-safe wiring (T6), README incl. debug-noise + factory-logger caveat (T7), framework-agnostic guard (T8). Exclusions-both-directions and response-only toggle/sampling are covered by T3 (`test_excluded_request/response`, `test_request_still_validated_when_responses_disabled`). The "first gate wins" semantics is exercised by `test_exclusion_takes_precedence_over_unversioned` and `test_exclusion_short_circuits_before_spec_load`.
- **Type consistency:** `RuntimeOptions(excludedPaths, validateResponses, responseSampleRate, sampler)` used identically in T3/T5/T6; `isExcluded`/`validatesResponses`/`shouldSampleResponse` names consistent; `runtimeOptions` is the 8th ctor param everywhere (named-arg call sites); new `SkipReason` values (`excluded`/`response_validation_disabled`/`not_sampled`) consistent across T1/T3/T6/T7.
- **No placeholders:** every code step shows full code; expected counts cumulative (114→137).
- **Carried plan notes:** `exclude` read as a plain array (`config('accord.exclude', [])` / `$config['exclude'] ?? []`), no single-string coercion (YAGNI, spec says array); README keeps the #9 factory-logger caveat.
