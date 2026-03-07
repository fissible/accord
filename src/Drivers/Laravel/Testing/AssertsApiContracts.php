<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Testing;

use Fissible\Accord\ContractValidator;
use Illuminate\Testing\TestResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * PHPUnit trait for asserting API contract adherence in Laravel feature tests.
 *
 * Usage:
 *   use AssertsApiContracts;
 *
 *   public function test_index_matches_contract(): void
 *   {
 *       $response = $this->getJson('/v1/users');
 *       $this->assertResponseMatchesContract($response);
 *   }
 */
trait AssertsApiContracts
{
    /**
     * Assert that the given test response matches the OpenAPI spec for the
     * version detected in the originating request URI.
     *
     * The ContractValidator is resolved from the service container, so it uses
     * the same config (spec path, failure mode) as the running application.
     */
    public function assertResponseMatchesContract(TestResponse $response): void
    {
        $factory   = new Psr17Factory();
        $bridge    = new PsrHttpFactory($factory, $factory, $factory, $factory);
        $validator = app(ContractValidator::class);

        // Reconstruct a PSR-7 request from the underlying Symfony request.
        $psrRequest  = $bridge->createRequest($response->baseRequest);
        $psrResponse = $bridge->createResponse($response->baseResponse);

        $result = $validator->validateResponse($psrResponse, $psrRequest);

        static::assertTrue(
            $result->valid,
            "API contract violation for {$result->version}:\n" . implode("\n", $result->errors),
        );
    }
}
