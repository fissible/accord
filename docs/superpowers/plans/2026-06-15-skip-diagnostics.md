# Skip-Validation Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Accord able to tell a user *whether and why* a request/response was skipped instead of validated, without changing the fail-open default.

**Architecture:** A framework-agnostic `SkipReason` enum is carried on `ValidationResult` (set at each fail-open bail-out). `ContractValidator` returns `skipped(reason)` instead of bare `valid()` and, when an opt-in `debug` flag is on, logs each skip at `debug` level with direction. A Laravel test-trait assertion (`assertResponseWasValidated`) turns "nothing was validated" into a test failure.

**Tech Stack:** PHP 8.2+, PHPUnit 11, cebe/php-openapi, nyholm/psr7, illuminate/http (dev).

**Spec:** `docs/superpowers/specs/2026-06-15-skip-diagnostics-design.md`

**Branch:** `feat/skip-diagnostics` (already checked out).

**Conventions:** `declare(strict_types=1)` on every file; no `public` non-readonly properties; framework code only under `src/Drivers/`. `Direction`, `SkipReason`, `ValidationResult`, `FailureMode` all live in namespace `Fissible\Accord`, so code inside `src/*.php` references them with NO `use` statement. Run the suite with `vendor/bin/phpunit --colors=never`. **Baseline: 89 tests passing, 5 pre-existing cebe deprecations** — the deprecation count must stay 5 (they are 5 distinct messages; loading more specs does not add new ones).

---

## File Structure

**Core (create):**
- `src/SkipReason.php` — the six skip reasons (string-backed enum).

**Core (modify):**
- `src/ValidationResult.php` — `skipReason` field + `skipped()` ctor + `wasValidated()`/`wasSkipped()`.
- `src/ContractValidator.php` — `skip()` helper, `debug` flag, `validateParameters` returns `[errors, evaluated]`, restructured `validateRequest`/`validateResponse`.

**Laravel driver (modify):**
- `src/Drivers/Laravel/config/accord.php` — `debug` key (documented with rationale).
- `src/Drivers/Laravel/Providers/AccordServiceProvider.php` — pass `debug`.
- `src/Drivers/Laravel/Testing/AssertsApiContracts.php` — `assertResponseWasValidated`.

**Core (modify, factory):**
- `src/AccordFactory.php` — pass `debug` (via `FILTER_VALIDATE_BOOLEAN`) + optional `logger` config key.

**Tests (create):**
- `src/../tests/Unit/SkipReasonTest.php`
- `tests/Fixtures/v2.yaml` — isolated spec for skip edge-cases.

**Tests (modify):**
- `tests/Unit/ValidationResultTest.php`
- `tests/Feature/ContractValidatorTest.php`
- `tests/Feature/LaravelServiceProviderTest.php`
- `tests/Unit/AccordFactoryTest.php`

**Docs (modify):**
- `README.md`

---

## Task 1: `SkipReason` enum

**Files:**
- Create: `src/SkipReason.php`
- Test: `tests/Unit/SkipReasonTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\SkipReason;
use PHPUnit\Framework\TestCase;

class SkipReasonTest extends TestCase
{
    public function test_backing_values_are_stable(): void
    {
        $this->assertSame('unversioned', SkipReason::Unversioned->value);
        $this->assertSame('missing_spec', SkipReason::MissingSpec->value);
        $this->assertSame('unmatched_operation', SkipReason::UnmatchedOperation->value);
        $this->assertSame('missing_request_schema', SkipReason::MissingRequestSchema->value);
        $this->assertSame('missing_response_schema', SkipReason::MissingResponseSchema->value);
        $this->assertSame('unsupported_media_type', SkipReason::UnsupportedMediaType->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SkipReasonTest`
Expected: FAIL — `Class "Fissible\Accord\SkipReason" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum SkipReason: string
{
    case Unversioned           = 'unversioned';
    case MissingSpec           = 'missing_spec';
    case UnmatchedOperation    = 'unmatched_operation';
    case MissingRequestSchema  = 'missing_request_schema';
    case MissingResponseSchema = 'missing_response_schema';
    case UnsupportedMediaType  = 'unsupported_media_type';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SkipReasonTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add src/SkipReason.php tests/Unit/SkipReasonTest.php
git commit -m "feat: add SkipReason enum for validation diagnostics (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `ValidationResult` skip metadata

**Files:**
- Modify: `src/ValidationResult.php`
- Test: `tests/Unit/ValidationResultTest.php` (add methods; keep existing tests)

The current `src/ValidationResult.php`:

```php
final class ValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $version,
        public readonly array $errors = [],
    ) {}

    public static function valid(string $version): self
    {
        return new self(valid: true, version: $version);
    }

    public static function invalid(array $errors, string $version): self
    {
        return new self(valid: false, version: $version, errors: $errors);
    }
}
```

- [ ] **Step 1: Write the failing tests**

Add `use Fissible\Accord\SkipReason;` to the imports of `tests/Unit/ValidationResultTest.php` (it is namespace `Fissible\Accord\Tests\Unit`). Add these methods to the existing test class:

```php
    public function test_skipped_is_valid_but_not_validated(): void
    {
        $result = ValidationResult::skipped(SkipReason::MissingSpec, 'v2');

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->errors);
        $this->assertSame(SkipReason::MissingSpec, $result->skipReason);
        $this->assertFalse($result->wasValidated());
        $this->assertTrue($result->wasSkipped());
    }

    public function test_valid_result_was_validated(): void
    {
        $result = ValidationResult::valid('v2');

        $this->assertTrue($result->wasValidated());
        $this->assertFalse($result->wasSkipped());
        $this->assertNull($result->skipReason);
    }

    public function test_invalid_result_was_validated(): void
    {
        $result = ValidationResult::invalid(['bad'], 'v2');

        $this->assertTrue($result->wasValidated());
        $this->assertFalse($result->wasSkipped());
        $this->assertNull($result->skipReason);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ValidationResultTest`
Expected: FAIL — `Call to undefined method ... ::skipped()` / undefined `wasValidated`.

- [ ] **Step 3: Add skip metadata**

Replace the entire contents of `src/ValidationResult.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord;

final class ValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $version,
        public readonly array $errors = [],
        public readonly ?SkipReason $skipReason = null,
    ) {}

    public static function valid(string $version): self
    {
        return new self(valid: true, version: $version);
    }

    public static function invalid(array $errors, string $version): self
    {
        return new self(valid: false, version: $version, errors: $errors);
    }

    public static function skipped(SkipReason $reason, string $version): self
    {
        return new self(valid: true, version: $version, skipReason: $reason);
    }

    /** True when the request/response was actually checked (a pass OR a genuine failure). */
    public function wasValidated(): bool
    {
        return $this->skipReason === null;
    }

    /** True when validation was skipped (fail-open); skips always have valid === true. */
    public function wasSkipped(): bool
    {
        return $this->skipReason !== null;
    }
}
```

(`SkipReason` is in the same `Fissible\Accord` namespace — no `use` needed.)

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 89 prior + 1 (Task 1) + 3 (Task 2) = 93 tests. Deprecations still 5.

- [ ] **Step 5: Commit**

```bash
git add src/ValidationResult.php tests/Unit/ValidationResultTest.php
git commit -m "feat: ValidationResult carries skip reason + wasValidated/wasSkipped (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `ContractValidator` — emit skip reasons + optional debug log

**Files:**
- Create: `tests/Fixtures/v2.yaml`
- Modify: `src/ContractValidator.php`
- Test: `tests/Feature/ContractValidatorTest.php` (add methods + 2 helpers; keep existing tests)

- [ ] **Step 1: Create the isolated edge-case fixture**

Create `tests/Fixtures/v2.yaml`:

```yaml
openapi: '3.0.3'
info:
  title: Skip Diagnostics Test API
  version: '2'
paths:
  /v2/items:
    get:
      operationId: items.index
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                type: array
                items:
                  type: object
    post:
      operationId: items.store
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required:
                - name
              properties:
                name:
                  type: string
              additionalProperties: false
      responses:
        '201':
          description: Created
          content:
            application/json:
              schema:
                type: object
  /v2/items/{id}:
    parameters:
      - name: id
        in: path
        required: true
        schema:
          type: integer
    get:
      operationId: items.show
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                type: object
  /v2/noreqschema:
    post:
      operationId: noreqschema
      requestBody:
        content:
          application/json: {}
      responses:
        '200':
          description: OK
  /v2/norespschema:
    get:
      operationId: norespschema
      responses:
        '200':
          description: OK
          content:
            application/json: {}
  /v2/noresponses:
    get:
      operationId: noresponses
  /v2/cookieonly:
    get:
      operationId: cookieonly
      parameters:
        - name: session
          in: cookie
          required: true
          schema:
            type: string
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                type: object
```

- [ ] **Step 2: Write the failing tests**

Add `use Fissible\Accord\SkipReason;` to the imports of `tests/Feature/ContractValidatorTest.php` (it already imports `Direction`, `RecordingLogger`, `ContractValidator`, `FailureMode`, `FileSpecSource`, `ValidationResult`, `VersionExtractor`, `Response`, `ServerRequest`). Add these two helpers and the test methods inside the class:

```php
    private function makeDebugValidator(RecordingLogger $logger, bool $debug = true): ContractValidator
    {
        return new ContractValidator(
            versionExtractor: $this->versionExtractor,
            specSource:       new FileSpecSource($this->fixturesPath, '{base}/{version}'),
            logger:           $logger,
            debug:            $debug,
        );
    }

    private function jsonResponse(int $status, string $body, string $type = 'application/json'): Response
    {
        return (new Response($status))
            ->withHeader('Content-Type', $type)
            ->withBody(\Nyholm\Psr7\Stream::create($body));
    }

    // --- skip diagnostics (#9) ---

    public function test_unversioned_request_is_skipped_with_reason(): void
    {
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/users'));

        $this->assertTrue($result->valid);
        $this->assertFalse($result->wasValidated());
        $this->assertSame(SkipReason::Unversioned, $result->skipReason);
    }

    public function test_missing_spec_is_skipped_with_reason(): void
    {
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v99/items'));

        $this->assertSame(SkipReason::MissingSpec, $result->skipReason);
    }

    public function test_unmatched_path_is_unmatched_operation(): void
    {
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v2/nope'));

        $this->assertSame(SkipReason::UnmatchedOperation, $result->skipReason);
    }

    public function test_unmatched_method_is_unmatched_operation(): void
    {
        $result = $this->makeValidator()->validateRequest(new ServerRequest('DELETE', '/v2/items'));

        $this->assertSame(SkipReason::UnmatchedOperation, $result->skipReason);
    }

    public function test_operation_with_no_params_and_no_body_is_missing_request_schema(): void
    {
        // GET /v2/items declares no parameters and no requestBody.
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v2/items'));

        $this->assertSame(SkipReason::MissingRequestSchema, $result->skipReason);
    }

    public function test_request_with_undeclared_content_type_is_unsupported_media_type(): void
    {
        $request = (new ServerRequest('POST', '/v2/items'))
            ->withHeader('Content-Type', 'text/plain')
            ->withBody(\Nyholm\Psr7\Stream::create('hi'));

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertSame(SkipReason::UnsupportedMediaType, $result->skipReason);
    }

    public function test_request_body_media_without_schema_is_missing_request_schema(): void
    {
        $request = (new ServerRequest('POST', '/v2/noreqschema'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{}'));

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertSame(SkipReason::MissingRequestSchema, $result->skipReason);
    }

    public function test_request_with_evaluated_param_counts_as_validated(): void
    {
        // GET /v2/items/5 has a schema-backed path param → params count, no body needed.
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v2/items/5'));

        $this->assertTrue($result->wasValidated());
        $this->assertNull($result->skipReason);
    }

    public function test_cookie_only_param_does_not_count_as_validated(): void
    {
        // GET /v2/cookieonly declares only a cookie param (unsupported location) and no body.
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v2/cookieonly'));

        $this->assertFalse($result->wasValidated());
        $this->assertSame(SkipReason::MissingRequestSchema, $result->skipReason);
    }

    public function test_response_unmatched_operation_is_skipped(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '[]'),
            new ServerRequest('DELETE', '/v2/items'),
        );

        $this->assertSame(SkipReason::UnmatchedOperation, $result->skipReason);
    }

    public function test_response_operation_without_responses_is_missing_response_schema(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}'),
            new ServerRequest('GET', '/v2/noresponses'),
        );

        $this->assertSame(SkipReason::MissingResponseSchema, $result->skipReason);
    }

    public function test_response_status_not_defined_is_missing_response_schema(): void
    {
        // GET /v2/items defines 200 only; a 404 has no matching response and no default.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(404, '{}'),
            new ServerRequest('GET', '/v2/items'),
        );

        $this->assertSame(SkipReason::MissingResponseSchema, $result->skipReason);
    }

    public function test_response_undeclared_content_type_is_unsupported_media_type(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, 'hi', 'text/plain'),
            new ServerRequest('GET', '/v2/items'),
        );

        $this->assertSame(SkipReason::UnsupportedMediaType, $result->skipReason);
    }

    public function test_response_media_without_schema_is_missing_response_schema(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}'),
            new ServerRequest('GET', '/v2/norespschema'),
        );

        $this->assertSame(SkipReason::MissingResponseSchema, $result->skipReason);
    }

    public function test_genuine_pass_was_validated(): void
    {
        $request = (new ServerRequest('POST', '/v2/items'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"name":"ok"}'));

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
        $this->assertNull($result->skipReason);
    }

    public function test_genuine_failure_was_validated(): void
    {
        $request = (new ServerRequest('POST', '/v2/items'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{}')); // missing required "name"

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertTrue($result->wasValidated());
        $this->assertNull($result->skipReason);
    }

    public function test_debug_logs_skip_with_direction_context(): void
    {
        $logger    = new RecordingLogger();
        $validator = $this->makeDebugValidator($logger);

        $validator->validateRequest(new ServerRequest('GET', '/v99/items')); // missing spec, request
        $validator->validateResponse(
            $this->jsonResponse(200, '{}'),
            new ServerRequest('GET', '/v99/items'),                          // missing spec, response
        );

        $this->assertCount(2, $logger->records);
        $this->assertSame('debug', $logger->records[0]['level']);
        $this->assertSame('Contract validation skipped', $logger->records[0]['message']);
        $this->assertSame('missing_spec', $logger->records[0]['context']['reason']);
        $this->assertSame('request', $logger->records[0]['context']['direction']);
        $this->assertSame('GET', $logger->records[0]['context']['method']);
        $this->assertSame('/v99/items', $logger->records[0]['context']['path']);
        $this->assertSame('response', $logger->records[1]['context']['direction']);
    }

    public function test_debug_off_logs_nothing(): void
    {
        $logger    = new RecordingLogger();
        $validator = $this->makeDebugValidator($logger, debug: false);

        $validator->validateRequest(new ServerRequest('GET', '/v99/items'));

        $this->assertCount(0, $logger->records);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ContractValidatorTest`
Expected: FAIL — `skipReason`/`wasValidated` not produced (validator still returns bare `valid()`); `debug` is not a known named parameter.

- [ ] **Step 4: Implement the validator changes**

In `src/ContractValidator.php`:

(a) Add the trailing `debug` constructor param (keep the existing six params unchanged):

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
    ) {}
```

(b) Replace the whole `validateRequest` method with:

```php
    public function validateRequest(ServerRequestInterface $request): ValidationResult
    {
        $version = $this->versionExtractor->extract($request);

        if ($version === null) {
            return $this->skip(SkipReason::Unversioned, 'unversioned', $request, Direction::Request);
        }

        $spec = $this->loadSpec($version);

        if ($spec === null) {
            return $this->skip(SkipReason::MissingSpec, $version, $request, Direction::Request);
        }

        $method = strtolower($request->getMethod());
        $path   = $request->getUri()->getPath();
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

(c) Replace the whole `validateResponse` method with:

```php
    public function validateResponse(ResponseInterface $response, ServerRequestInterface $request): ValidationResult
    {
        $version = $this->versionExtractor->extract($request);

        if ($version === null) {
            return $this->skip(SkipReason::Unversioned, 'unversioned', $request, Direction::Response);
        }

        $spec = $this->loadSpec($version);

        if ($spec === null) {
            return $this->skip(SkipReason::MissingSpec, $version, $request, Direction::Response);
        }

        $method    = strtolower($request->getMethod());
        $path      = $request->getUri()->getPath();
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

(d) Add the `skip()` helper. Put it immediately after `handleFailure()`:

```php
    private function skip(
        SkipReason $reason,
        string $version,
        ServerRequestInterface $request,
        Direction $direction,
    ): ValidationResult {
        if ($this->debug) {
            $this->logger->debug('Contract validation skipped', [
                'version'   => $version,
                'method'    => strtoupper($request->getMethod()),
                'path'      => $request->getUri()->getPath(),
                'direction' => $direction->value,
                'reason'    => $reason->value,
            ]);
        }

        return ValidationResult::skipped($reason, $version);
    }
```

(e) Change `validateParameters` to also report how many schema-backed params were evaluated. Replace the whole method with:

```php
    /** @return array{0: string[], 1: int} */
    private function validateParameters(
        Operation $operation,
        PathItem $pathItem,
        string $template,
        ServerRequestInterface $request,
    ): array {
        $errors         = [];
        $evaluated      = 0;
        $pathParameters = $this->extractPathParameters($template, $request->getUri()->getPath());

        foreach ($this->collectParameters($pathItem, $operation) as $parameter) {
            if (!$parameter->schema instanceof Schema || !in_array($parameter->in, ['query', 'path', 'header'], true)) {
                continue;
            }

            [$present, $rawValue] = $this->parameterValue($parameter, $request, $pathParameters);

            if (!$present) {
                if ($parameter->required) {
                    $errors[]  = sprintf('Missing required %s parameter "%s"', $parameter->in, $parameter->name);
                    $evaluated++;
                }

                continue;
            }

            $value      = $this->deserializeParameterValue($parameter, $rawValue);
            $errors     = array_merge($errors, $this->validateParameterValue($parameter, $value));
            $evaluated++;
        }

        return [$errors, $evaluated];
    }
```

(`SkipReason` and `Direction` are in the same namespace — no new `use` statements.)

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 93 prior + 18 new = 111 tests. Deprecations still 5.

- [ ] **Step 6: Commit**

```bash
git add src/ContractValidator.php tests/Feature/ContractValidatorTest.php tests/Fixtures/v2.yaml
git commit -m "feat: ContractValidator emits skip reasons + optional debug logging (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Laravel config — `debug` knob

**Files:**
- Modify: `src/Drivers/Laravel/config/accord.php`

No isolated test (config data); exercised by Task 5.

- [ ] **Step 1: Add the `debug` block**

In `src/Drivers/Laravel/config/accord.php`, add this block immediately before the final closing `];` (after the last existing key):

```php

    /*
    |--------------------------------------------------------------------------
    | Debug Diagnostics
    |--------------------------------------------------------------------------
    | When true, Accord logs (at "debug" level) every request and response it
    | SKIPPED instead of validated, with the reason — unversioned, missing_spec,
    | unmatched_operation, missing_request_schema, missing_response_schema, or
    | unsupported_media_type. Use it to answer "is my API actually being
    | validated, and if not, why?". Off by default and zero overhead when off;
    | leave it off in production unless you are diagnosing silent non-validation.
    |
    */
    'debug' => (bool) env('ACCORD_DEBUG', false),
```

- [ ] **Step 2: Verify syntax + suite**

Run: `php -l src/Drivers/Laravel/config/accord.php` → "No syntax errors detected".
Run: `vendor/bin/phpunit --colors=never` → 111 tests, 5 deprecations.

- [ ] **Step 3: Commit**

```bash
git add src/Drivers/Laravel/config/accord.php
git commit -m "feat: add documented ACCORD_DEBUG config knob (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `AccordServiceProvider` passes `debug`

**Files:**
- Modify: `src/Drivers/Laravel/Providers/AccordServiceProvider.php`
- Test: `tests/Feature/LaravelServiceProviderTest.php` (add 1 method; reuse existing scaffolding)

- [ ] **Step 1: Write the failing test**

In `tests/Feature/LaravelServiceProviderTest.php`, add `use Nyholm\Psr7\ServerRequest;` to the `Fissible\Accord\Tests\Feature` namespace `use` block. Add `'accord.debug' => false,` to the `LaravelConfig::$values` array in `setUp()`. Add this method to the class (match the existing 8-space method indentation):

```php
        public function test_debug_config_enables_skip_logging(): void
        {
            LaravelConfig::$values['accord.debug'] = true;

            $logger = new RecordingLogger();
            $app    = new FakeLaravelContainer([LoggerInterface::class => $logger]);

            (new AccordServiceProvider($app))->register();

            $validator = $app->make(ContractValidator::class);
            $validator->validateRequest(new ServerRequest('GET', '/v99/x')); // missing spec → skip

            $this->assertNotEmpty($logger->records);
            $this->assertSame('debug', $logger->records[0]['level']);
            $this->assertSame('missing_spec', $logger->records[0]['context']['reason']);
        }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter LaravelServiceProviderTest`
Expected: FAIL — the resolved validator has `debug=false` (provider doesn't pass it yet), so nothing is logged and `assertNotEmpty` fails.

- [ ] **Step 3: Pass `debug` in the provider**

In `src/Drivers/Laravel/Providers/AccordServiceProvider.php`, in the `ContractValidator::class` singleton closure, add the `debug` named argument to the `new ContractValidator(...)` call (place it after `responseFailureMode: $responseMode,`):

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

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 111 prior + 1 = 112 tests. Deprecations 5.

- [ ] **Step 5: Commit**

```bash
git add src/Drivers/Laravel/Providers/AccordServiceProvider.php tests/Feature/LaravelServiceProviderTest.php
git commit -m "feat: Laravel provider passes debug flag to validator (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `AccordFactory` — `debug` (bool-safe) + optional `logger`

**Files:**
- Modify: `src/AccordFactory.php`
- Test: `tests/Unit/AccordFactoryTest.php` (add methods + a handler helper)

The current relevant block of `src/AccordFactory.php` (`make`):

```php
        [$requestMode, $responseMode] = FailureMode::resolvePair($config['failure_mode'] ?? 'exception');
        $failureCallable = $config['failure_callable'] ?? null;

        $validator = new ContractValidator(
            versionExtractor:    $versionExtractor,
            specSource:          $specSource,
            failureMode:         $requestMode,
            failureCallable:     $failureCallable,
            responseFailureMode: $responseMode,
        );
```

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/AccordFactoryTest.php`, ensure these imports are present (add any missing): `use Fissible\Accord\Tests\Support\RecordingLogger;`, `use Nyholm\Psr7\Response;`, `use Nyholm\Psr7\ServerRequest;`, `use Psr\Http\Message\ResponseInterface;`, `use Psr\Http\Message\ServerRequestInterface;`, `use Psr\Http\Server\RequestHandlerInterface;`. Add this helper and the two tests inside the class:

```php
    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    public function test_factory_logger_enables_debug_skip_logging(): void
    {
        $logger = new RecordingLogger();

        $middleware = AccordFactory::make(
            ['debug' => true, 'logger' => $logger],
            dirname(__DIR__) . '/Fixtures',
        );

        // /v99 has no spec → MissingSpec skip on the request side.
        $middleware->process(new ServerRequest('GET', '/v99/x'), $this->passthroughHandler());

        $this->assertNotEmpty($logger->records);
        $this->assertSame('missing_spec', $logger->records[0]['context']['reason']);
    }

    public function test_factory_string_false_debug_is_off(): void
    {
        $logger = new RecordingLogger();

        $middleware = AccordFactory::make(
            ['debug' => 'false', 'logger' => $logger], // string 'false' must NOT enable debug
            dirname(__DIR__) . '/Fixtures',
        );

        $middleware->process(new ServerRequest('GET', '/v99/x'), $this->passthroughHandler());

        $this->assertCount(0, $logger->records);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter AccordFactoryTest`
Expected: FAIL — `test_factory_logger_enables_debug_skip_logging` logs nothing (factory passes neither `logger` nor `debug`), and a raw build would not honor the `logger` key.

- [ ] **Step 3: Update the factory**

In `src/AccordFactory.php`, add these imports near the existing `use` statements:

```php
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
```

Replace the block shown above with:

```php
        [$requestMode, $responseMode] = FailureMode::resolvePair($config['failure_mode'] ?? 'exception');
        $failureCallable = $config['failure_callable'] ?? null;

        $logger = ($config['logger'] ?? null) instanceof LoggerInterface
            ? $config['logger']
            : new NullLogger();

        $validator = new ContractValidator(
            versionExtractor:    $versionExtractor,
            specSource:          $specSource,
            failureMode:         $requestMode,
            failureCallable:     $failureCallable,
            logger:              $logger,
            responseFailureMode: $responseMode,
            debug:               filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
```

(Do **not** use `(bool)` on `$config['debug']` — `(bool) 'false' === true`. `FILTER_VALIDATE_BOOLEAN` maps `'false'`/`'0'`/`'no'`/`''` → `false`.)

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. 112 prior + 2 = 114 tests. Deprecations 5.

- [ ] **Step 5: Commit**

```bash
git add src/AccordFactory.php tests/Unit/AccordFactoryTest.php
git commit -m "feat: AccordFactory honors debug (bool-safe) + optional logger key (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: `AssertsApiContracts::assertResponseWasValidated`

**Files:**
- Modify: `src/Drivers/Laravel/Testing/AssertsApiContracts.php`

No dedicated automated test: this trait depends on `Illuminate\Testing\TestResponse` (a consuming-app testing class that is **not** installed in this package, exactly like the existing `assertResponseMatchesContract`). Its core — `ValidationResult::wasValidated()` and the validator's skip reasons — is fully unit-tested in Tasks 2 and 3. Verification here is lint + suite-green.

- [ ] **Step 1: Add the assertion method**

In `src/Drivers/Laravel/Testing/AssertsApiContracts.php`, add this method to the trait (after the existing `assertResponseMatchesContract`):

```php
    /**
     * Assert that the given response was actually validated against the spec — i.e.
     * not silently skipped (missing spec, unmatched route, no schema, …). Fails with
     * the skip reason so "nothing was validated" surfaces instead of passing quietly.
     */
    public function assertResponseWasValidated(TestResponse $response): void
    {
        $factory   = new Psr17Factory();
        $bridge    = new PsrHttpFactory($factory, $factory, $factory, $factory);
        $validator = app(ContractValidator::class);

        $psrRequest  = $bridge->createRequest($response->baseRequest);
        $psrResponse = $bridge->createResponse($response->baseResponse);

        $result = $validator->validateResponse($psrResponse, $psrRequest);

        static::assertTrue(
            $result->wasValidated(),
            "Expected the response to be validated against the contract for {$result->version}, "
                . 'but it was skipped'
                . ($result->skipReason !== null ? ": {$result->skipReason->value}" : '')
                . '.',
        );
    }
```

- [ ] **Step 2: Verify syntax + suite**

Run: `php -l src/Drivers/Laravel/Testing/AssertsApiContracts.php` → "No syntax errors detected".
Run: `vendor/bin/phpunit --colors=never` → 114 tests, 5 deprecations (unchanged — this trait is not collected as a test).

- [ ] **Step 3: Commit**

```bash
git add src/Drivers/Laravel/Testing/AssertsApiContracts.php
git commit -m "feat: assertResponseWasValidated trait assertion catches silent skips (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: README documentation

**Files:**
- Modify: `README.md`

No test (docs).

- [ ] **Step 1: Add the `debug` config line**

In `README.md`, find the Laravel published-config snippet (it contains `'request_violation_status' => env('ACCORD_REQUEST_VIOLATION_STATUS', 422), ...`). Add, after that line:

```php
    'debug'          => env('ACCORD_DEBUG', false), // log skipped (non-validated) requests/responses + why
```

- [ ] **Step 2: Add a diagnostics section**

Immediately after that config snippet's closing fence, add this markdown:

```markdown
**Diagnostics — "was anything actually validated?"** Accord fails open by design (a
missing spec, unmatched route, or undeclared schema all pass), which can hide the fact
that nothing ran. Two tools make that visible:

- Set `ACCORD_DEBUG=true` (or `'debug' => true`) to log, at `debug` level, every request
  and response Accord **skipped** and why (`missing_spec`, `unmatched_operation`,
  `missing_request_schema`, `missing_response_schema`, `unsupported_media_type`,
  `unversioned`). It's off by default and free when off — turn it on while diagnosing,
  not in steady-state production.
- On any `ValidationResult`, `wasValidated()` is `true` only when the request/response was
  actually checked (a pass *or* a genuine failure); `wasSkipped()` and `$result->skipReason`
  tell you which fail-open branch was taken.
- In Laravel feature tests, `assertResponseWasValidated($response)` fails (naming the skip
  reason) if the response was silently skipped — use it alongside
  `assertResponseMatchesContract` to catch "green because nothing validated".

For the Slim/Mezzio `AccordFactory`, debug logging requires a logger: pass a PSR-3
`LoggerInterface` under the `logger` config key (without it, `debug` has nowhere to write):

```php
$middleware = AccordFactory::make(['debug' => true, 'logger' => $psrLogger], $basePath);
```
```

- [ ] **Step 3: Verify suite unaffected**

Run: `vendor/bin/phpunit --colors=never` → 114 tests, 5 deprecations.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: document debug diagnostics, wasValidated/wasSkipped, and factory logger key (#9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Final verification

- [ ] **Step 1: Full suite + clean tree**

```bash
vendor/bin/phpunit --colors=never
git diff --check
```
Expected: 114 tests pass, 5 deprecations (no new). `git diff --check` prints nothing.

- [ ] **Step 2: Core stayed framework-agnostic**

```bash
grep -rn 'Illuminate\\' src/ | grep -v src/Drivers/
```
Expected: **no output**.

- [ ] **Step 3: Push + PR (only if the user asks)**

Do not push/PR unless asked. When asked:

```bash
git push -u origin feat/skip-diagnostics
gh pr create --title "feat: skip-validation diagnostics (#9)" --body "$(cat <<'EOF'
Implements #9. Diagnostics-only (fail-open preserved).

- `SkipReason` enum + `ValidationResult::skipped()` / `wasValidated()` / `wasSkipped()`
- `ContractValidator` returns skip reasons at every fail-open point; optional `debug` flag logs skips (with direction) at debug level
- "Param validation counts": a matched request with ≥1 evaluated schema-backed query/path/header param is `wasValidated()`, never a skip
- Laravel `ACCORD_DEBUG` config + provider wiring; `AccordFactory` `debug` (bool-safe) + optional `logger` key
- `assertResponseWasValidated()` test-trait assertion catches "green because nothing validated"

Backward compatible: skips keep `valid=true`; new params optional; default behavior unchanged.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes (author)

- **Spec coverage:** SkipReason (T1), ValidationResult metadata + helpers (T2), validator skip reasons + the precise param-count rule + media-type classification + direction-aware debug log (T3, with reality-checked fixtures), config knob documented (T4), provider wiring (T5), factory bool-safe debug + logger key (T6), trait assertion (T7), README incl. factory-logger caveat (T8), framework-agnostic guard (T9). The `operation===null` vs `responses===null` split and the `MissingResponseSchema` mapping are covered by T3 tests `test_response_unmatched_operation_is_skipped` and `test_response_operation_without_responses_is_missing_response_schema`.
- **Type consistency:** `skip(SkipReason,string,ServerRequestInterface,Direction)`; `validateParameters` → `array{0:string[],1:int}` destructured as `[$paramErrors,$paramsEvaluated]`; `debug` is the 7th ctor param everywhere (provider/factory use named args); `skipReason` property + `wasValidated()`/`wasSkipped()` used consistently across T2/T3/T7.
- **No placeholders:** every code step shows full code; fixture verified against cebe; expected counts cumulative (89→114).
