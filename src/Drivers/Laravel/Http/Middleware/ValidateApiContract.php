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
