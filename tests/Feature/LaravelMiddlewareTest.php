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
