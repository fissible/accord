<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Feature;

use Fissible\Accord\ContractValidator;
use Fissible\Accord\Direction;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\FailureMode;
use Fissible\Accord\FileSpecSource;
use Fissible\Accord\RuntimeOptions;
use Fissible\Accord\Tests\Support\RecordingLogger;
use Fissible\Accord\ValidationResult;
use Fissible\Accord\SkipReason;
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
            specSource:       new FileSpecSource($this->fixturesPath, '{base}/{version}'),
            failureMode:      $mode,
            failureCallable:  $callable,
        );
    }

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

    public function test_valid_get_request_parameters_pass(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('GET', '/v1/roster?page=2&status=active'))
            ->withHeader('X-Client', 'ios');

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->valid);
    }

    public function test_missing_required_query_parameter_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('GET', '/v1/roster?status=active'))
            ->withHeader('X-Client', 'ios');

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertContains('Missing required query parameter "page"', $result->errors);
    }

    public function test_invalid_query_parameter_type_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('GET', '/v1/roster?page=abc'))
            ->withHeader('X-Client', 'ios');

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('query parameter "page"', implode("\n", $result->errors));
    }

    public function test_invalid_query_parameter_enum_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('GET', '/v1/roster?page=2&status=suspended'))
            ->withHeader('X-Client', 'ios');

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('query parameter "status"', implode("\n", $result->errors));
    }

    public function test_valid_query_array_parameter_passes(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('GET', '/v1/roster?page=2&ids=1,2,3'))
            ->withHeader('X-Client', 'ios');

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->valid);
    }

    public function test_invalid_query_array_parameter_item_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('GET', '/v1/roster?page=2&ids=1,nope,3'))
            ->withHeader('X-Client', 'ios');

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('query parameter "ids"', implode("\n", $result->errors));
    }

    public function test_invalid_header_parameter_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = (new ServerRequest('GET', '/v1/roster?page=2'))
            ->withHeader('X-Client', 'io');

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('header parameter "X-Client"', implode("\n", $result->errors));
    }

    public function test_missing_required_header_parameter_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/roster?page=2');

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertContains('Missing required header parameter "X-Client"', $result->errors);
    }

    public function test_valid_path_parameter_passes(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/users/123');

        $result = $validator->validateRequest($request);

        $this->assertTrue($result->valid);
    }

    public function test_invalid_path_parameter_fails(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/users/not-an-int');

        $result = $validator->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('path parameter "id"', implode("\n", $result->errors));
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

    public function test_parameterized_path_response_body_is_validated(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/users/123');
        $response  = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"id":"not-an-int","name":"Alice"}'));

        $result = $validator->validateResponse($response, $request);

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_parameterized_path_does_not_match_extra_segments(): void
    {
        $validator = $this->makeValidator();
        $request   = new ServerRequest('GET', '/v1/users/123/extra');
        $response  = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"id":"not-an-int","name":"Alice"}'));

        $result = $validator->validateResponse($response, $request);

        $this->assertTrue($result->valid);
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
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v2/items/5'));

        $this->assertTrue($result->wasValidated());
        $this->assertNull($result->skipReason);
    }

    public function test_cookie_only_param_does_not_count_as_validated(): void
    {
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
            ->withBody(\Nyholm\Psr7\Stream::create('{}'));

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertTrue($result->wasValidated());
        $this->assertNull($result->skipReason);
    }

    public function test_debug_logs_skip_with_direction_context(): void
    {
        $logger    = new RecordingLogger();
        $validator = $this->makeDebugValidator($logger);

        $validator->validateRequest(new ServerRequest('GET', '/v99/items'));
        $validator->validateResponse(
            $this->jsonResponse(200, '{}'),
            new ServerRequest('GET', '/v99/items'),
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

    // --- wildcard media-type matching (#10) ---

    public function test_exact_media_type_wins_over_wildcard(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{"id":1}', 'application/json'),
            new ServerRequest('GET', '/v4/exact-wins'),
        );

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_subtype_wildcard_matches_concrete_media_type(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'application/json'),
            new ServerRequest('GET', '/v4/subtype'),
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_full_wildcard_matches_any_media_type(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'text/plain'),
            new ServerRequest('GET', '/v4/anytype'),
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_wildcard_derivation_is_case_insensitive(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'Application/JSON'),
            new ServerRequest('GET', '/v4/subtype'),
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_unmatched_media_type_still_skips(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '{}', 'text/plain'),
            new ServerRequest('GET', '/v4/exact-only'),
        );

        $this->assertSame(SkipReason::UnsupportedMediaType, $result->skipReason);
    }

    public function test_request_body_wildcard_media_is_matched(): void
    {
        $request = (new ServerRequest('POST', '/v4/upload'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{}'));

        $result = $this->makeValidator()->validateRequest($request);

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

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
        $validator = $this->makeOptionsValidator(new RuntimeOptions(['/v99/*']));

        $result = $validator->validateRequest(new ServerRequest('GET', '/v99/items'));

        $this->assertSame(SkipReason::Excluded, $result->skipReason);
    }

    public function test_response_validation_disabled_skips_response(): void
    {
        $validator = $this->makeOptionsValidator(new RuntimeOptions(validateResponses: false));

        $result = $validator->validateResponse(
            $this->jsonResponse(200, '[]'),
            new ServerRequest('GET', '/v2/items'),
        );

        $this->assertSame(SkipReason::ResponseValidationDisabled, $result->skipReason);
    }

    public function test_request_still_validated_when_responses_disabled(): void
    {
        $validator = $this->makeOptionsValidator(new RuntimeOptions(validateResponses: false));

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

    // --- servers base-path fallback (#10) ---

    public function test_server_base_path_matches_relative_operation(): void
    {
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '[]', 'application/json'),
            new ServerRequest('GET', '/v5/users'),
        );

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_path_params_extracted_on_stripped_route(): void
    {
        // /v5/users/5 → strip /v5 → /users/5 against /users/{id}; id=5 validates (integer).
        // Without effective-path threading, id would be "missing required" → invalid.
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v5/users/5'));

        $this->assertTrue($result->valid);
        $this->assertTrue($result->wasValidated());
    }

    public function test_server_base_stripping_is_segment_safe(): void
    {
        // /v50/users loads v50.yaml (base /v5). /v5 must NOT be stripped from /v50/... .
        $result = $this->makeValidator()->validateRequest(new ServerRequest('GET', '/v50/users'));

        $this->assertSame(SkipReason::UnmatchedOperation, $result->skipReason);
    }

    public function test_full_path_spec_still_matches_as_is(): void
    {
        // v1.yaml has no servers; its full-path /v1/users must still match as-is.
        $result = $this->makeValidator()->validateResponse(
            $this->jsonResponse(200, '[{"id":1,"name":"a"}]', 'application/json'),
            new ServerRequest('GET', '/v1/users'),
        );

        $this->assertTrue($result->valid);
    }
}
