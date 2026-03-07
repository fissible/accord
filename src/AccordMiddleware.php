<?php

declare(strict_types=1);

namespace Fissible\Accord;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware. Works natively with Slim and Mezzio.
 * The Laravel driver wraps this via ValidateApiContract.
 */
class AccordMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ContractValidator $validator,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestResult = $this->validator->validateRequest($request);

        if (!$requestResult->valid) {
            $this->validator->handleFailure($requestResult);
        }

        $response = $handler->handle($request);

        $responseResult = $this->validator->validateResponse($response, $request);

        if (!$responseResult->valid) {
            $this->validator->handleFailure($responseResult);
        }

        return $response;
    }
}
