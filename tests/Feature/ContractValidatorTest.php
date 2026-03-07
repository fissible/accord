<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Feature;

use Fissible\Accord\ContractValidator;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\FailureMode;
use Fissible\Accord\SpecResolver;
use Fissible\Accord\ValidationResult;
use Fissible\Accord\VersionExtractor;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class ContractValidatorTest extends TestCase
{
    private string $fixturesPath;
    private VersionExtractor $versionExtractor;

    protected function setUp(): void
    {
        $this->fixturesPath     = dirname(__DIR__) . '/Fixtures';
        $this->versionExtractor = new VersionExtractor();
    }

    private function makeValidator(FailureMode $mode = FailureMode::Exception, ?callable $callable = null): ContractValidator
    {
        return new ContractValidator(
            versionExtractor: $this->versionExtractor,
            specResolver:     new SpecResolver($this->fixturesPath, '{base}/{version}.json'),
            failureMode:      $mode,
            failureCallable:  $callable,
        );
    }

    // -------------------------------------------------------------------------
    // Request validation
    // -------------------------------------------------------------------------

    public function test_unversioned_request_always_passes(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/users');

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->valid);
        $this->assertSame('unversioned', $result->version);
    }

    public function test_versioned_request_with_no_spec_passes(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v99/users');

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->valid);
    }

    public function test_valid_post_request_body_passes(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('POST', '/v1/users'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"name":"Alice"}'));

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->valid);
    }

    public function test_invalid_post_request_body_fails(): void
    {
        $validator = $this->makeValidator();
        // Body has an extra field not allowed by additionalProperties: false
        $request   = (new ServerRequest('POST', '/v1/users'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"name":"Alice","role":"admin"}'));

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    // -------------------------------------------------------------------------
    // Response validation
    // -------------------------------------------------------------------------

    public function test_valid_response_body_passes(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/users');
        $response  = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('[{"id":1,"name":"Alice"}]'));

        $result = $validator->validateResponse($response, $request);

        $this->assertTrue($result->valid);
    }

    public function test_response_with_wrong_type_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/users');
        // id is a string, not an integer
        $response  = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('[{"id":"not-an-int","name":"Alice"}]'));

        $result = $validator->validateResponse($response, $request);

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_response_with_missing_required_field_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/users');
        // name is required but missing
        $response  = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('[{"id":1}]'));

        $result = $validator->validateResponse($response, $request);

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    // -------------------------------------------------------------------------
    // Failure modes
    // -------------------------------------------------------------------------

    public function test_exception_failure_mode_throws(): void
    {
        $validator = $this->makeValidator(FailureMode::Exception);
        $result    = ValidationResult::invalid(['something broke'], 'v1');

        $this->expectException(ContractViolationException::class);
        $validator->handleFailure($result);
    }

    public function test_log_failure_mode_does_not_throw(): void
    {
        $validator = $this->makeValidator(FailureMode::Log);
        $result    = ValidationResult::invalid(['something broke'], 'v1');

        $validator->handleFailure($result); // must not throw

        $this->assertTrue(true);
    }

    public function test_callable_failure_mode_invokes_callable(): void
    {
        $called  = false;
        $validator = $this->makeValidator(
            FailureMode::Callable,
            function (ValidationResult $r) use (&$called) { $called = true; },
        );
        $result = ValidationResult::invalid(['something broke'], 'v1');

        $validator->handleFailure($result);

        $this->assertTrue($called);
    }

    public function test_violation_exception_exposes_result(): void
    {
        $result    = ValidationResult::invalid(['id must be integer'], 'v1');
        $exception = new ContractViolationException($result);

        $this->assertSame($result, $exception->result);
        $this->assertStringContainsString('v1', $exception->getMessage());
        $this->assertStringContainsString('id must be integer', $exception->getMessage());
    }
}
