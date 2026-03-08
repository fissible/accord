<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Schema;
use Fissible\Accord\Exception\ContractViolationException;
use JsonSchema\Validator as JsonSchemaValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ContractValidator
{
    /** @var array<string, OpenApi> */
    private array $specCache = [];

    public function __construct(
        private readonly VersionExtractor $versionExtractor,
        private readonly SpecSourceInterface $specSource,
        private readonly FailureMode $failureMode = FailureMode::Exception,
        /** @var callable(ValidationResult): void|null */
        private readonly mixed $failureCallable = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function validateRequest(ServerRequestInterface $request): ValidationResult
    {
        $version = $this->versionExtractor->extract($request);

        if ($version === null) {
            return ValidationResult::valid('unversioned');
        }

        $spec = $this->loadSpec($version);

        if ($spec === null) {
            return ValidationResult::valid($version);
        }

        $method    = strtolower($request->getMethod());
        $path      = $request->getUri()->getPath();
        $operation = $this->findOperation($spec, $method, $path);

        if ($operation === null || $operation->requestBody === null) {
            return ValidationResult::valid($version);
        }

        $contentType = $this->parseContentType($request->getHeaderLine('Content-Type'));
        $mediaType   = $operation->requestBody->content[$contentType] ?? null;

        if ($mediaType === null || $mediaType->schema === null) {
            return ValidationResult::valid($version);
        }

        $body   = (string) $request->getBody();
        $errors = $this->validateJsonBody($body, $mediaType->schema);

        return empty($errors)
            ? ValidationResult::valid($version)
            : ValidationResult::invalid($errors, $version);
    }

    public function validateResponse(ResponseInterface $response, ServerRequestInterface $request): ValidationResult
    {
        $version = $this->versionExtractor->extract($request);

        if ($version === null) {
            return ValidationResult::valid('unversioned');
        }

        $spec = $this->loadSpec($version);

        if ($spec === null) {
            return ValidationResult::valid($version);
        }

        $method    = strtolower($request->getMethod());
        $path      = $request->getUri()->getPath();
        $operation = $this->findOperation($spec, $method, $path);

        if ($operation === null || $operation->responses === null) {
            return ValidationResult::valid($version);
        }

        $statusCode  = (string) $response->getStatusCode();
        $specResponse = $operation->responses->getResponse($statusCode)
            ?? $operation->responses->getResponse('default');

        if ($specResponse === null) {
            return ValidationResult::valid($version);
        }

        $contentType = $this->parseContentType($response->getHeaderLine('Content-Type'));
        $mediaType   = $specResponse->content[$contentType] ?? null;

        if ($mediaType === null || $mediaType->schema === null) {
            return ValidationResult::valid($version);
        }

        $body   = (string) $response->getBody();
        $errors = $this->validateJsonBody($body, $mediaType->schema);

        return empty($errors)
            ? ValidationResult::valid($version)
            : ValidationResult::invalid($errors, $version);
    }

    public function handleFailure(ValidationResult $result): void
    {
        match ($this->failureMode) {
            FailureMode::Exception => throw new ContractViolationException($result),
            FailureMode::Log       => $this->logger->warning('API contract violation', [
                'version' => $result->version,
                'errors'  => $result->errors,
            ]),
            FailureMode::Callable  => ($this->failureCallable)($result),
        };
    }

    private function findOperation(OpenApi $spec, string $method, string $path): ?Operation
    {
        foreach ($spec->paths as $template => $pathItem) {
            if ($this->pathMatches($template, $path)) {
                return $pathItem->getOperations()[$method] ?? null;
            }
        }

        return null;
    }

    private function pathMatches(string $template, string $path): bool
    {
        $pattern = preg_replace('/\{[^}]+\}/', '[^/]+', preg_quote($template, '#'));

        return (bool) preg_match('#^' . $pattern . '$#', $path);
    }

    /** @return string[] */
    private function validateJsonBody(string $body, Schema $schema): array
    {
        if ($body === '') {
            return [];
        }

        $data      = json_decode($body);
        $schemaObj = $schema->getSerializableData();

        $validator = new JsonSchemaValidator();
        $validator->validate($data, $schemaObj);

        if ($validator->isValid()) {
            return [];
        }

        return array_map(
            fn(array $e) => trim(($e['property'] ? $e['property'] . ': ' : '') . $e['message']),
            $validator->getErrors(),
        );
    }

    private function parseContentType(string $header): string
    {
        // Strip parameters like "; charset=utf-8"
        return trim(explode(';', $header)[0]);
    }

    private function loadSpec(string $version): ?OpenApi
    {
        if (array_key_exists($version, $this->specCache)) {
            return $this->specCache[$version];
        }

        return $this->specCache[$version] = $this->specSource->load($version);
    }
}
