<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Http\Middleware;

use Closure;
use Fissible\Accord\ContractValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Laravel HTTP middleware wrapping the PSR-7/15 core validator.
 *
 * Requires symfony/psr-http-message-bridge and nyholm/psr7:
 *   composer require symfony/psr-http-message-bridge nyholm/psr7
 */
class ValidateApiContract
{
    public function __construct(
        private readonly ContractValidator $validator,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // TODO: bridge Illuminate Request → PSR-7 ServerRequestInterface
        // $psrRequest = (new PsrHttpFactory(...))->createRequest($request);
        // $requestResult = $this->validator->validateRequest($psrRequest);
        // if (!$requestResult->valid) { $this->validator->handleFailure($requestResult); }

        $response = $next($request);

        // TODO: bridge Illuminate Response → PSR-7 ResponseInterface, validate, bridge back

        return $response;
    }
}
