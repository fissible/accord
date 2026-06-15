# Per-Direction Failure Modes + HTTP-Aware Rendering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let accord apply different failure modes to request vs response contract violations, and render request violations as a proper Laravel 4xx (default 422) instead of a 500 — while a bad response stays a server error.

**Architecture:** A new `Direction` enum flows from the middlewares into `ContractValidator::handleFailure`, which selects request- or response-specific `FailureMode`. The core stays framework-agnostic; the `ContractViolationException` carries only `direction`, and the Laravel `ValidateApiContract` middleware maps a request-direction violation to a `JsonResponse`. Config accepts a string (both directions) or an array (`['request'=>..,'response'=>..]`), parsed by one shared `FailureMode::resolvePair`.

**Tech Stack:** PHP 8.2+, PHPUnit 11, cebe/php-openapi, nyholm/psr7, symfony/psr-http-message-bridge, illuminate/http (added as a dev dependency for the Laravel middleware tests).

**Spec:** `docs/superpowers/specs/2026-06-14-per-direction-failure-modes-and-rendering-design.md`

**Branch:** `feat/per-direction-failure-modes` (already checked out).

**Conventions to honor:** `declare(strict_types=1)` on every file; no `public` properties except readonly promoted constructor props; framework code only under `src/Drivers/`. Run the suite with `vendor/bin/phpunit --colors=never`. Baseline is **60 tests passing, 5 pre-existing deprecations** — do not introduce new deprecations.

---

## File Structure

**Core (create):**
- `src/Direction.php` — `Request | Response` string-backed enum.

**Core (modify):**
- `src/FailureMode.php` — add static `resolvePair(string|array|null)`.
- `src/Exception/ContractViolationException.php` — add trailing `Direction $direction` param.
- `src/ContractValidator.php` — add `?FailureMode $responseFailureMode`, direction-aware `handleFailure`, `direction` in log context.
- `src/AccordMiddleware.php` — pass `Direction::Request` / `Direction::Response`.
- `src/AccordFactory.php` — parse failure mode via `resolvePair`.

**Laravel driver (modify):**
- `src/Drivers/Laravel/config/accord.php` — add `request_violation_status`; document array `failure_mode`.
- `src/Drivers/Laravel/Http/Middleware/ValidateApiContract.php` — direction-aware calls + render request violations.
- `src/Drivers/Laravel/Providers/AccordServiceProvider.php` — wire `resolvePair`, bind `ValidateApiContract` with the status guard.

**Tests (create):**
- `tests/Support/RecordingLogger.php` — shared PSR-3 recording double.
- `tests/Unit/DirectionTest.php`
- `tests/Unit/ContractViolationExceptionTest.php`
- `tests/Feature/AccordMiddlewareTest.php`
- `tests/Feature/LaravelMiddlewareTest.php` — needs illuminate/http.

**Tests (modify):**
- `tests/Unit/FailureModeTest.php` — create (no FailureMode test exists today).
- `tests/Feature/ContractValidatorTest.php` — add per-direction handleFailure tests.
- `tests/Feature/LaravelServiceProviderTest.php` — add array-config + binding tests.
- `tests/Unit/AccordFactoryTest.php` — add array-config regression tests.

**Other (modify):**
- `composer.json` — add `illuminate/http` to `require-dev`.
- `README.md` — document array config, `request_violation_status`, rendering behavior.

---

## Task 1: `Direction` enum

**Files:**
- Create: `src/Direction.php`
- Test: `tests/Unit/DirectionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\Direction;
use PHPUnit\Framework\TestCase;

class DirectionTest extends TestCase
{
    public function test_backing_values_are_stable_strings(): void
    {
        $this->assertSame('request', Direction::Request->value);
        $this->assertSame('response', Direction::Response->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DirectionTest`
Expected: FAIL — `Error: Class "Fissible\Accord\Direction" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum Direction: string
{
    case Request  = 'request';
    case Response = 'response';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DirectionTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add src/Direction.php tests/Unit/DirectionTest.php
git commit -m "feat: add Direction enum for request/response failure routing

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Shared `RecordingLogger` test double

This is test infrastructure (no behavior to test). Tasks 5, 8, and the middleware tests use it.

**Files:**
- Create: `tests/Support/RecordingLogger.php`

- [ ] **Step 1: Create the double**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
```

Note: `autoload-dev` already maps `Fissible\Accord\Tests\` → `tests/`, so this resolves as `Fissible\Accord\Tests\Support\RecordingLogger` with no `composer dump-autoload` needed. `tests/Support` is not a registered PHPUnit suite, so it is not collected as tests.

- [ ] **Step 2: Commit**

```bash
git add tests/Support/RecordingLogger.php
git commit -m "test: add shared RecordingLogger PSR-3 double

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `FailureMode::resolvePair`

**Files:**
- Create: `tests/Unit/FailureModeTest.php`
- Modify: `src/FailureMode.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\FailureMode;
use PHPUnit\Framework\TestCase;
use ValueError;

class FailureModeTest extends TestCase
{
    public function test_scalar_applies_to_both_directions(): void
    {
        $this->assertSame([FailureMode::Log, FailureMode::Log], FailureMode::resolvePair('log'));
    }

    public function test_full_array_maps_each_direction(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Log],
            FailureMode::resolvePair(['request' => 'exception', 'response' => 'log']),
        );
    }

    public function test_missing_response_key_falls_back_to_request(): void
    {
        $this->assertSame(
            [FailureMode::Log, FailureMode::Log],
            FailureMode::resolvePair(['request' => 'log']),
        );
    }

    public function test_missing_request_key_falls_back_to_exception_default(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Log],
            FailureMode::resolvePair(['response' => 'log']),
        );
    }

    public function test_empty_array_falls_back_to_exception(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Exception],
            FailureMode::resolvePair([]),
        );
    }

    public function test_null_falls_back_to_exception(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Exception],
            FailureMode::resolvePair(null),
        );
    }

    public function test_unknown_scalar_throws(): void
    {
        $this->expectException(ValueError::class);
        FailureMode::resolvePair('bogus');
    }

    public function test_blank_scalar_throws(): void
    {
        $this->expectException(ValueError::class);
        FailureMode::resolvePair('');
    }

    public function test_present_but_unknown_array_value_throws(): void
    {
        $this->expectException(ValueError::class);
        FailureMode::resolvePair(['request' => 'nope']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter FailureModeTest`
Expected: FAIL — `Error: Call to undefined method Fissible\Accord\FailureMode::resolvePair()`.

- [ ] **Step 3: Implement `resolvePair`**

Replace the entire contents of `src/FailureMode.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum FailureMode: string
{
    case Exception = 'exception';
    case Log       = 'log';
    case Callable  = 'callable';

    /**
     * Resolve a string|array failure-mode config into [request, response] modes.
     *
     * - A scalar string applies to both directions.
     * - An array may set 'request' and/or 'response'; a MISSING key falls back
     *   (response → request, request → Exception default).
     * - null / [] fall back to Exception for both.
     * - A present-but-unknown string (scalar or array value) throws ValueError
     *   via self::from — typos are never silently swallowed.
     *
     * @param string|array<string, string>|null $config
     * @return array{0: FailureMode, 1: FailureMode}
     */
    public static function resolvePair(string|array|null $config): array
    {
        if ($config === null) {
            return [self::Exception, self::Exception];
        }

        if (is_array($config)) {
            $request  = isset($config['request'])  ? self::from($config['request'])  : self::Exception;
            $response = isset($config['response']) ? self::from($config['response']) : $request;

            return [$request, $response];
        }

        $mode = self::from($config);

        return [$mode, $mode];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter FailureModeTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/FailureMode.php tests/Unit/FailureModeTest.php
git commit -m "feat: add FailureMode::resolvePair for string|array config (#5)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `ContractViolationException` carries direction (ABI-preserving)

**Files:**
- Create: `tests/Unit/ContractViolationExceptionTest.php`
- Modify: `src/Exception/ContractViolationException.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\Direction;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\ValidationResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ContractViolationExceptionTest extends TestCase
{
    public function test_defaults_to_request_direction(): void
    {
        $e = new ContractViolationException(ValidationResult::invalid(['x'], 'v1'));

        $this->assertSame(Direction::Request, $e->direction);
    }

    public function test_carries_supplied_direction(): void
    {
        $e = new ContractViolationException(
            ValidationResult::invalid(['x'], 'v1'),
            direction: Direction::Response,
        );

        $this->assertSame(Direction::Response, $e->direction);
    }

    public function test_preserves_legacy_positional_abi(): void
    {
        $previous = new RuntimeException('root');

        $e = new ContractViolationException(
            ValidationResult::invalid(['x'], 'v1'),
            'custom message',
            42,
            $previous,
        );

        $this->assertSame('custom message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
        $this->assertSame(Direction::Request, $e->direction);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ContractViolationExceptionTest`
Expected: FAIL — `Error: Undefined property ... $direction` (the property does not exist yet).

- [ ] **Step 3: Add the trailing `direction` parameter**

Replace the entire contents of `src/Exception/ContractViolationException.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Exception;

use Fissible\Accord\Direction;
use Fissible\Accord\ValidationResult;
use RuntimeException;

class ContractViolationException extends RuntimeException
{
    public function __construct(
        public readonly ValidationResult $result,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly Direction $direction = Direction::Request,
    ) {
        parent::__construct(
            $message ?: sprintf(
                'API contract violation for version %s: %s',
                $result->version,
                implode('; ', $result->errors),
            ),
            $code,
            $previous,
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ContractViolationExceptionTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Exception/ContractViolationException.php tests/Unit/ContractViolationExceptionTest.php
git commit -m "feat: ContractViolationException carries Direction (trailing param, ABI preserved) (#5)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `ContractValidator` — direction-aware failure handling

**Files:**
- Modify: `src/ContractValidator.php` (constructor + `handleFailure`)
- Test: `tests/Feature/ContractValidatorTest.php` (add tests in the "Failure modes" section)

- [ ] **Step 1: Write the failing tests**

Add these imports at the top of `tests/Feature/ContractValidatorTest.php` (after the existing `use` block):

```php
use Fissible\Accord\Direction;
use Fissible\Accord\Tests\Support\RecordingLogger;
```

Add these methods inside `class ContractValidatorTest` (e.g. just below the existing `makeValidator` helper):

```php
    private function makeDirectionalValidator(
        FailureMode $request,
        FailureMode $response,
        RecordingLogger $logger,
    ): ContractValidator {
        return new ContractValidator(
            versionExtractor:    $this->versionExtractor,
            specSource:          new FileSpecSource($this->fixturesPath, '{base}/{version}'),
            failureMode:         $request,
            failureCallable:     null,
            logger:              $logger,
            responseFailureMode: $response,
        );
    }

    public function test_request_direction_uses_request_mode_and_throws(): void
    {
        $validator = $this->makeDirectionalValidator(FailureMode::Exception, FailureMode::Log, new RecordingLogger());

        $this->expectException(ContractViolationException::class);
        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Request);
    }

    public function test_response_direction_uses_response_mode_and_logs(): void
    {
        $logger    = new RecordingLogger();
        $validator = $this->makeDirectionalValidator(FailureMode::Exception, FailureMode::Log, $logger);

        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Response);

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
    }

    public function test_log_context_includes_direction(): void
    {
        $logger    = new RecordingLogger();
        $validator = $this->makeDirectionalValidator(FailureMode::Log, FailureMode::Log, $logger);

        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Response);

        $this->assertSame('response', $logger->records[0]['context']['direction']);
    }

    public function test_scalar_config_uses_same_mode_for_response_backward_compat(): void
    {
        // responseFailureMode null → response falls back to the single failureMode (Log).
        $logger    = new RecordingLogger();
        $validator = new ContractValidator(
            versionExtractor: $this->versionExtractor,
            specSource:       new FileSpecSource($this->fixturesPath, '{base}/{version}'),
            failureMode:      FailureMode::Log,
            failureCallable:  null,
            logger:           $logger,
        );

        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Response);

        $this->assertCount(1, $logger->records);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter ContractValidatorTest`
Expected: FAIL — `handleFailure` does not accept a second argument / `responseFailureMode` is not a known named parameter.

- [ ] **Step 3: Update the constructor and `handleFailure`**

In `src/ContractValidator.php`, change the constructor to add the trailing param (leave the existing five params unchanged):

```php
    public function __construct(
        private readonly VersionExtractor $versionExtractor,
        private readonly SpecSourceInterface $specSource,
        private readonly FailureMode $failureMode = FailureMode::Exception,
        /** @var callable(ValidationResult): void|null */
        private readonly mixed $failureCallable = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?FailureMode $responseFailureMode = null,
    ) {}
```

Replace the existing `handleFailure` method with:

```php
    public function handleFailure(ValidationResult $result, Direction $direction = Direction::Request): void
    {
        $mode = $direction === Direction::Response
            ? ($this->responseFailureMode ?? $this->failureMode)
            : $this->failureMode;

        match ($mode) {
            FailureMode::Exception => throw new ContractViolationException($result, direction: $direction),
            FailureMode::Log       => $this->logger->warning('API contract violation', [
                'version'   => $result->version,
                'errors'    => $result->errors,
                'direction' => $direction->value,
            ]),
            FailureMode::Callable  => ($this->failureCallable)($result),
        };
    }
```

`Direction` is in the same `Fissible\Accord` namespace, so no `use` statement is required.

- [ ] **Step 4: Run the full suite to verify pass + no regressions**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS. Test count is the prior 60 + 4 new from this task + the new Unit tests from Tasks 1/3/4 already committed. No new deprecations (still 5).

- [ ] **Step 5: Commit**

```bash
git add src/ContractValidator.php tests/Feature/ContractValidatorTest.php
git commit -m "feat: direction-aware handleFailure with response failure mode + direction log context (#5)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `AccordMiddleware` (PSR-15) passes direction

**Files:**
- Modify: `src/AccordMiddleware.php`
- Create: `tests/Feature/AccordMiddlewareTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Feature;

use Fissible\Accord\AccordMiddleware;
use Fissible\Accord\ContractValidator;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\FailureMode;
use Fissible\Accord\FileSpecSource;
use Fissible\Accord\Tests\Support\RecordingLogger;
use Fissible\Accord\VersionExtractor;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AccordMiddlewareTest extends TestCase
{
    private function makeMiddleware(
        FailureMode $request,
        FailureMode $response,
        RecordingLogger $logger,
    ): AccordMiddleware {
        $validator = new ContractValidator(
            versionExtractor:    new VersionExtractor(),
            specSource:          new FileSpecSource(dirname(__DIR__) . '/Fixtures', '{base}/{version}'),
            failureMode:         $request,
            failureCallable:     null,
            logger:              $logger,
            responseFailureMode: $response,
        );

        return new AccordMiddleware($validator);
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function test_request_violation_uses_request_mode(): void
    {
        // request=Exception → a missing required query param throws before the handler runs.
        $middleware = $this->makeMiddleware(FailureMode::Exception, FailureMode::Log, new RecordingLogger());
        $request    = new ServerRequest('GET', '/v1/roster'); // missing required page + X-Client

        $this->expectException(ContractViolationException::class);
        $middleware->process($request, $this->handlerReturning(new Response(200)));
    }

    public function test_response_violation_uses_response_mode(): void
    {
        // request=Log so the (also-invalid) request only logs; response=Exception throws on the bad body.
        $logger     = new RecordingLogger();
        $middleware = $this->makeMiddleware(FailureMode::Log, FailureMode::Exception, $logger);
        $request    = (new ServerRequest('GET', '/v1/roster'))
            ->withQueryParams(['page' => '1'])
            ->withHeader('X-Client', 'abc');

        $badResponse = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"not":"an-array"}')); // schema expects array

        try {
            $middleware->process($request, $this->handlerReturning($badResponse));
            $this->fail('Expected ContractViolationException for the response violation');
        } catch (ContractViolationException $e) {
            $this->assertSame('response', $e->direction->value);
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter AccordMiddlewareTest`
Expected: FAIL on `test_response_violation_uses_response_mode` — today `handleFailure` is called without a direction, so the response violation is handled with the request mode (`Log`) and never throws, so the `fail()` line fires. (`test_request_violation_uses_request_mode` may already pass since request defaults to Request direction.)

- [ ] **Step 3: Pass direction in `process`**

Replace the body of `process` in `src/AccordMiddleware.php`:

```php
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestResult = $this->validator->validateRequest($request);

        if (!$requestResult->valid) {
            $this->validator->handleFailure($requestResult, Direction::Request);
        }

        $response = $handler->handle($request);

        $responseResult = $this->validator->validateResponse($response, $request);

        if (!$responseResult->valid) {
            $this->validator->handleFailure($responseResult, Direction::Response);
        }

        return $response;
    }
```

Add `use Fissible\Accord\Direction;`? No — `AccordMiddleware` is in namespace `Fissible\Accord`, same as `Direction`, so no import is needed.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter AccordMiddlewareTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/AccordMiddleware.php tests/Feature/AccordMiddlewareTest.php
git commit -m "feat: PSR-15 middleware routes request/response violations per direction (#5)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Laravel config — `request_violation_status` + array `failure_mode` docs

**Files:**
- Modify: `src/Drivers/Laravel/config/accord.php`

No isolated test (config file is data). It is exercised by the provider tests in Task 9.

- [ ] **Step 1: Update the `failure_mode` comment block**

In `src/Drivers/Laravel/config/accord.php`, replace the `Failure Mode` comment block (the lines from `| Failure Mode` through the `*/`) so it documents the array form. Replace:

```php
    | How contract violations are reported. Options: exception | log | callable
    |
    */
    'failure_mode' => env('ACCORD_FAILURE_MODE', 'exception'),
```

with:

```php
    | How contract violations are reported. Options: exception | log | callable
    |
    | May be a single value (applied to both directions) or an array with
    | separate 'request' and 'response' modes, e.g.:
    |   'failure_mode' => ['request' => 'exception', 'response' => 'log'],
    | A missing array key falls back (response → request, request → exception).
    |
    */
    'failure_mode' => env('ACCORD_FAILURE_MODE', 'exception'),
```

- [ ] **Step 2: Add the `request_violation_status` block**

Immediately after the `'failure_mode' => ...,` line, add:

```php

    /*
    |--------------------------------------------------------------------------
    | Request Violation Status
    |--------------------------------------------------------------------------
    | HTTP status returned (in the Laravel driver) when a REQUEST violates the
    | contract under exception mode. Must be a 4xx; anything else falls back to
    | 422. Response violations are never rendered as a client error.
    |
    */
    'request_violation_status' => (int) env('ACCORD_REQUEST_VIOLATION_STATUS', 422),
```

- [ ] **Step 3: Verify the suite still passes (config parse sanity)**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS (no new failures; this change is additive data).

- [ ] **Step 4: Commit**

```bash
git add src/Drivers/Laravel/config/accord.php
git commit -m "feat: Laravel config for array failure_mode + request_violation_status (#5, #6)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: `ValidateApiContract` — direction-aware + HTTP rendering

**Files:**
- Modify: `composer.json` (add `illuminate/http` to `require-dev`)
- Modify: `src/Drivers/Laravel/Http/Middleware/ValidateApiContract.php`
- Create: `tests/Feature/LaravelMiddlewareTest.php`

- [ ] **Step 1: Add illuminate/http as a dev dependency**

Run:

```bash
composer require --dev --no-interaction "illuminate/http:^10.0|^11.0|^12.0"
```

Expected: composer resolves and installs `illuminate/http` (+ its transitive deps) and updates `composer.json`/`composer.lock`. If composer picks a version requiring a newer PHP than 8.4, constrain to `^11.0`.

- [ ] **Step 2: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Feature;

use Fissible\Accord\ContractValidator;
use Fissible\Accord\Direction;
use Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\FailureMode;
use Fissible\Accord\FileSpecSource;
use Fissible\Accord\Tests\Support\RecordingLogger;
use Fissible\Accord\VersionExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class LaravelMiddlewareTest extends TestCase
{
    private function makeValidator(
        FailureMode $request,
        FailureMode $response,
        ?RecordingLogger $logger = null,
    ): ContractValidator {
        return new ContractValidator(
            versionExtractor:    new VersionExtractor(),
            specSource:          new FileSpecSource(dirname(__DIR__) . '/Fixtures', '{base}/{version}'),
            failureMode:         $request,
            failureCallable:     null,
            logger:              $logger ?? new RecordingLogger(),
            responseFailureMode: $response,
        );
    }

    public function test_request_violation_renders_422_json(): void
    {
        $middleware = new ValidateApiContract($this->makeValidator(FailureMode::Exception, FailureMode::Log), 422);
        $request    = Request::create('/v1/roster', 'GET'); // missing required page + X-Client

        $response = $middleware->handle($request, fn () => new JsonResponse([], 200));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertSame('Request does not satisfy the API contract', $data['message']);
        $this->assertNotEmpty($data['errors']);
    }

    public function test_request_violation_status_is_configurable(): void
    {
        $middleware = new ValidateApiContract($this->makeValidator(FailureMode::Exception, FailureMode::Log), 418);
        $request    = Request::create('/v1/roster', 'GET');

        $response = $middleware->handle($request, fn () => new JsonResponse([], 200));

        $this->assertSame(418, $response->getStatusCode());
    }

    public function test_response_violation_in_log_mode_passes_through(): void
    {
        $logger     = new RecordingLogger();
        $middleware = new ValidateApiContract($this->makeValidator(FailureMode::Exception, FailureMode::Log, $logger), 422);

        $request = Request::create('/v1/roster?page=1', 'GET');
        $request->headers->set('X-Client', 'abc');

        $original = new JsonResponse(['not' => 'an-array'], 200); // 200 schema expects an array
        $response = $middleware->handle($request, fn () => $original);

        $this->assertSame($original, $response);            // untouched
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $logger->records);
        $this->assertSame('response', $logger->records[0]['context']['direction']);
    }

    public function test_response_violation_in_exception_mode_propagates_as_server_error(): void
    {
        $middleware = new ValidateApiContract($this->makeValidator(FailureMode::Exception, FailureMode::Exception), 422);

        $request = Request::create('/v1/roster?page=1', 'GET');
        $request->headers->set('X-Client', 'abc');

        try {
            $middleware->handle($request, fn () => new JsonResponse(['not' => 'an-array'], 200));
            $this->fail('Expected the response violation to propagate, not render as a client error');
        } catch (ContractViolationException $e) {
            $this->assertSame(Direction::Response, $e->direction);
        }
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter LaravelMiddlewareTest`
Expected: FAIL — `ValidateApiContract::__construct()` does not yet accept a second argument, and request violations currently throw (rendered as no JSON).

- [ ] **Step 4: Implement direction-aware handling + rendering**

Replace the entire contents of `src/Drivers/Laravel/Http/Middleware/ValidateApiContract.php` with:

```php
<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Http\Middleware;

use Closure;
use Fissible\Accord\ContractValidator;
use Fissible\Accord\Direction;
use Fissible\Accord\Exception\ContractViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class ValidateApiContract
{
    private readonly PsrHttpFactory $bridge;

    public function __construct(
        private readonly ContractValidator $validator,
        private readonly int $requestViolationStatus = 422,
    ) {
        $factory      = new Psr17Factory();
        $this->bridge = new PsrHttpFactory($factory, $factory, $factory, $factory);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $psrRequest    = $this->bridge->createRequest($request);
        $requestResult = $this->validator->validateRequest($psrRequest);

        if (!$requestResult->valid) {
            try {
                $this->validator->handleFailure($requestResult, Direction::Request);
            } catch (ContractViolationException $e) {
                return new JsonResponse([
                    'message' => 'Request does not satisfy the API contract',
                    'errors'  => $e->result->errors,
                ], $this->requestViolationStatus);
            }
        }

        $response = $next($request);

        $psrResponse    = $this->bridge->createResponse($response);
        $responseResult = $this->validator->validateResponse($psrResponse, $psrRequest);

        if (!$responseResult->valid) {
            // Response violations are a server-side problem. Under exception mode this
            // propagates (Laravel renders a 500); under log mode it is logged and the
            // original response passes through untouched. Never rendered as a 4xx.
            $this->validator->handleFailure($responseResult, Direction::Response);
        }

        return $response;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter LaravelMiddlewareTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock src/Drivers/Laravel/Http/Middleware/ValidateApiContract.php tests/Feature/LaravelMiddlewareTest.php
git commit -m "feat: render request violations as JSON 4xx; response violations stay server errors (#6)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: `AccordServiceProvider` — wire resolvePair + bind middleware with status

**Files:**
- Modify: `src/Drivers/Laravel/Providers/AccordServiceProvider.php`
- Test: `tests/Feature/LaravelServiceProviderTest.php` (append tests; reuse the file's existing `FakeLaravelContainer`, `LaravelConfig`, `RecordingLogger`, `FakeLogManager`, and the global `config`/`base_path` stubs)

- [ ] **Step 1: Write the failing tests**

Add these imports to the `Fissible\Accord\Tests\Feature` namespace `use` block at the top of `tests/Feature/LaravelServiceProviderTest.php`:

```php
use Fissible\Accord\Direction;
use Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\ValidationResult;
use ReflectionProperty;
```

(Note: `ContractValidator`, `AccordServiceProvider`, `LoggerInterface` are already imported in this file. `ValidationResult` may already be imported — if so, skip the duplicate.)

Add these methods inside `class LaravelServiceProviderTest`:

```php
    public function test_array_failure_mode_logs_response_violations(): void
    {
        LaravelConfig::$values['accord.failure_mode'] = ['request' => 'exception', 'response' => 'log'];

        $logger = new RecordingLogger();
        $app    = new FakeLaravelContainer([LoggerInterface::class => $logger]);

        (new AccordServiceProvider($app))->register();

        $validator = $app->make(ContractValidator::class);
        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Response);

        $this->assertCount(1, $logger->records);
    }

    public function test_array_failure_mode_throws_on_request_violations(): void
    {
        LaravelConfig::$values['accord.failure_mode'] = ['request' => 'exception', 'response' => 'log'];

        $app = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);
        (new AccordServiceProvider($app))->register();

        $validator = $app->make(ContractValidator::class);

        $this->expectException(ContractViolationException::class);
        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Request);
    }

    public function test_provider_binds_middleware_with_configured_status(): void
    {
        LaravelConfig::$values['accord.request_violation_status'] = 418;

        $app = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);
        (new AccordServiceProvider($app))->register();

        $middleware = $app->make(ValidateApiContract::class);

        $this->assertSame(418, $this->readStatus($middleware));
    }

    public function test_provider_casts_string_status_and_guards_non_4xx(): void
    {
        $app = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);

        LaravelConfig::$values['accord.request_violation_status'] = '418';
        (new AccordServiceProvider($app))->register();
        $this->assertSame(418, $this->readStatus($app->make(ValidateApiContract::class)));

        // Out-of-4xx falls back to 422. Fresh container so the singleton is rebuilt.
        $app2 = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);
        LaravelConfig::$values['accord.request_violation_status'] = 500;
        (new AccordServiceProvider($app2))->register();
        $this->assertSame(422, $this->readStatus($app2->make(ValidateApiContract::class)));
    }

    private function readStatus(ValidateApiContract $middleware): int
    {
        $property = new ReflectionProperty(ValidateApiContract::class, 'requestViolationStatus');

        return $property->getValue($middleware);
    }
```

Also add `'accord.request_violation_status' => 422,` to the `LaravelConfig::$values` array in this file's `setUp()` so the base config has the key (next to the existing `'accord.log_channel' => null,` line).

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter LaravelServiceProviderTest`
Expected: FAIL — `Nothing bound for Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract` (provider does not bind it yet), and array config would currently hit `FailureMode::from(array)`.

- [ ] **Step 3: Update the provider**

In `src/Drivers/Laravel/Providers/AccordServiceProvider.php`, add these imports near the existing `use` statements:

```php
use Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract;
```

Replace the `ContractValidator` singleton registration (the `$this->app->singleton(ContractValidator::class, ...)` closure) with:

```php
        $this->app->singleton(ContractValidator::class, function () {
            [$requestMode, $responseMode] = FailureMode::resolvePair(config('accord.failure_mode'));
            $failureCallable = config('accord.failure_callable');

            if (is_array($failureCallable) || is_string($failureCallable)) {
                $failureCallable = $this->app->make(...(array) $failureCallable);
            }

            return new ContractValidator(
                versionExtractor:    $this->app->make(VersionExtractor::class),
                specSource:          $this->app->make(SpecSourceInterface::class),
                failureMode:         $requestMode,
                failureCallable:     $failureCallable,
                logger:              $this->resolveLogger(),
                responseFailureMode: $responseMode,
            );
        });
```

Immediately after the `AccordMiddleware` singleton registration (the existing `$this->app->singleton(AccordMiddleware::class, ...)` block), add a binding for the Laravel middleware:

```php
        $this->app->singleton(ValidateApiContract::class, fn () => new ValidateApiContract(
            $this->app->make(ContractValidator::class),
            $this->resolveRequestViolationStatus(),
        ));
```

Add this private helper to the class (next to the existing `resolveLogger`):

```php
    private function resolveRequestViolationStatus(): int
    {
        $status = (int) config('accord.request_violation_status', 422);

        return ($status >= 400 && $status <= 499) ? $status : 422;
    }
```

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS, including the existing `test_laravel_provider_injects_default_logger` and `test_laravel_provider_uses_configured_log_channel` (unchanged behavior). No new deprecations.

- [ ] **Step 5: Commit**

```bash
git add src/Drivers/Laravel/Providers/AccordServiceProvider.php tests/Feature/LaravelServiceProviderTest.php
git commit -m "feat: provider resolves per-direction modes and binds middleware with guarded status (#5, #6)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: `AccordFactory` — parse failure mode via resolvePair

**Files:**
- Modify: `src/AccordFactory.php`
- Test: `tests/Unit/AccordFactoryTest.php` (append tests)

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Unit/AccordFactoryTest.php` (use the existing imports; add `use Fissible\Accord\AccordMiddleware;` if not already present):

```php
    public function test_make_accepts_array_failure_mode_without_error(): void
    {
        $middleware = AccordFactory::make(
            ['failure_mode' => ['request' => 'exception', 'response' => 'log']],
            dirname(__DIR__) . '/Fixtures',
        );

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }

    public function test_make_still_accepts_scalar_failure_mode(): void
    {
        $middleware = AccordFactory::make(
            ['failure_mode' => 'log'],
            dirname(__DIR__) . '/Fixtures',
        );

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter AccordFactoryTest`
Expected: FAIL on `test_make_accepts_array_failure_mode_without_error` — `FailureMode::from()` receives an array → `TypeError`.

- [ ] **Step 3: Use resolvePair in the factory**

In `src/AccordFactory.php`, replace:

```php
        $failureMode     = FailureMode::from($config['failure_mode'] ?? 'exception');
        $failureCallable = $config['failure_callable'] ?? null;

        $validator = new ContractValidator(
            versionExtractor: $versionExtractor,
            specSource:       $specSource,
            failureMode:      $failureMode,
            failureCallable:  $failureCallable,
        );
```

with:

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

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter AccordFactoryTest`
Expected: PASS (existing AccordFactory tests + 2 new).

- [ ] **Step 5: Commit**

```bash
git add src/AccordFactory.php tests/Unit/AccordFactoryTest.php
git commit -m "feat: AccordFactory parses string|array failure_mode via resolvePair (#5)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: README documentation

**Files:**
- Modify: `README.md`

No test (docs). Document the array config form, `request_violation_status`, and the rendering behavior.

- [ ] **Step 1: Update the config snippet**

In `README.md`, find the published-config snippet (the array beginning `'failure_mode' => env('ACCORD_FAILURE_MODE', 'exception'),`). Add a `request_violation_status` line and a note about the array form. After the `'log_channel' => env('ACCORD_LOG_CHANNEL'),` line (added in the prior PR), add:

```php
    'request_violation_status' => env('ACCORD_REQUEST_VIOLATION_STATUS', 422), // request 4xx; non-4xx → 422
```

Below that snippet, add a short prose paragraph:

```markdown
**Per-direction failure modes.** `failure_mode` may be a single value applied to both
directions, or an array with separate `request` and `response` modes:

```php
'failure_mode' => ['request' => 'exception', 'response' => 'log'],
```

In the Laravel driver, a **request** violation under `exception` mode is rendered as a JSON
response (`{ "message": ..., "errors": [...] }`) with `request_violation_status` (default
`422`; a non-4xx value falls back to `422`). A **response** violation is treated as a
server-side problem: under `exception` mode it surfaces as a 500, and under `log` mode it is
logged while the original response passes through unchanged — it is never rendered as a 4xx.
```

- [ ] **Step 2: Verify the suite is unaffected**

Run: `vendor/bin/phpunit --colors=never`
Expected: PASS (docs-only change).

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document per-direction failure modes and request violation rendering (#5, #6)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: Final verification

- [ ] **Step 1: Full suite, clean tree**

Run:

```bash
vendor/bin/phpunit --colors=never
git diff --check
```

Expected: All tests pass (baseline 60 + the new tests from Tasks 1, 3, 4, 5, 6, 8, 9, 10). Deprecations remain **5** (no new ones). `git diff --check` prints nothing.

- [ ] **Step 2: Confirm the core stayed framework-agnostic**

Run:

```bash
grep -rn "Illuminate\\\\" src --include=*.php | grep -v "src/Drivers/"
```

Expected: **no output** — no `Illuminate\` reference leaked outside `src/Drivers/`.

- [ ] **Step 3: Push and open the PR (only if the user asks)**

Do not push or open a PR unless the user requests it. When asked:

```bash
git push -u origin feat/per-direction-failure-modes
gh pr create --title "feat: per-direction failure modes + HTTP-aware rendering (#5, #6)" --body "$(cat <<'EOF'
Implements #5 (per-direction failure modes) and #6 (HTTP-aware rendering).

- `Direction` enum + `FailureMode::resolvePair` (string|array config, shared by factory & provider)
- Direction-aware `ContractValidator::handleFailure` with response failure mode + direction in log context
- `ContractViolationException` carries `direction` (trailing param — ABI preserved)
- Laravel: request violations render as JSON 4xx (configurable, default 422, non-4xx → 422); response violations stay server errors
- Provider binds `ValidateApiContract` so the configured status actually applies
- `illuminate/http` added to require-dev for middleware integration tests

Fully backward compatible: scalar `failure_mode` behaves exactly as before.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review Notes (author)

- **Spec coverage:** Direction enum (T1), resolvePair string/array/fallback/throw (T3), exception direction + ABI (T4), validator per-direction + log context + backward-compat (T5), PSR-15 directions (T6), config status + array docs (T7), middleware rendering + response-stays-server-error (T8), provider binding + cast + 4xx guard (T9), factory array support (T10), README (T11), framework-agnostic guard (T12). All spec sections map to a task.
- **Type consistency:** `responseFailureMode` (named arg) used identically in T5/T9/T10; `requestViolationStatus` private prop name matches the reflection read in T9; `Direction::Request|Response` and `->value` used consistently.
- **No placeholders:** every code step shows full code; every run step states the expected result.
