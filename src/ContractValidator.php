<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Fissible\Accord\Exception\ContractViolationException;
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
        private readonly SpecResolver $specResolver,
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

        // TODO: validate request method/path/headers/body against $spec
        $errors = [];

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

        // TODO: validate response status/headers/body against $spec
        $errors = [];

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

    private function loadSpec(string $version): ?OpenApi
    {
        if (array_key_exists($version, $this->specCache)) {
            return $this->specCache[$version];
        }

        if (!$this->specResolver->exists($version)) {
            return $this->specCache[$version] = null;
        }

        return $this->specCache[$version] = Reader::readFromJsonFile(
            $this->specResolver->resolve($version),
        );
    }
}
