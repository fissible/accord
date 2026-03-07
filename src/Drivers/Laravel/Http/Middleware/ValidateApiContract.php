<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Http\Middleware;

use Closure;
use Fissible\Accord\ContractValidator;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class ValidateApiContract
{
    private readonly PsrHttpFactory $bridge;

    public function __construct(
        private readonly ContractValidator $validator,
    ) {
        $factory      = new Psr17Factory();
        $this->bridge = new PsrHttpFactory($factory, $factory, $factory, $factory);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $psrRequest    = $this->bridge->createRequest($request);
        $requestResult = $this->validator->validateRequest($psrRequest);

        if (!$requestResult->valid) {
            $this->validator->handleFailure($requestResult);
        }

        $response = $next($request);

        $psrResponse    = $this->bridge->createResponse($response);
        $responseResult = $this->validator->validateResponse($psrResponse, $psrRequest);

        if (!$responseResult->valid) {
            $this->validator->handleFailure($responseResult);
        }

        return $response;
    }
}
