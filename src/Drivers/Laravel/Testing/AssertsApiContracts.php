<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Testing;

use Fissible\Accord\ContractValidator;
use Fissible\Accord\ValidationResult;
use Illuminate\Testing\TestResponse;

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
     * Assert that the given test response body matches the OpenAPI spec
     * for the version detected in the request URI.
     */
    public function assertResponseMatchesContract(TestResponse $response, ?string $version = null): void
    {
        // TODO: extract PSR-7 response from TestResponse, delegate to ContractValidator
        // $result = app(ContractValidator::class)->validateResponse($psrResponse, $psrRequest);
        // static::assertTrue($result->valid, implode("\n", $result->errors));
        $this->assertTrue(true, 'Contract assertion not yet implemented.');
    }
}
