<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
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
        private readonly ?FailureMode $responseFailureMode = null,
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

        $method = strtolower($request->getMethod());
        $path   = $request->getUri()->getPath();
        $match  = $this->findPathItem($spec, $path);

        if ($match === null) {
            return ValidationResult::valid($version);
        }

        $operation = $match['pathItem']->getOperations()[$method] ?? null;

        if ($operation === null) {
            return ValidationResult::valid($version);
        }

        $errors = $this->validateParameters($operation, $match['pathItem'], $match['template'], $request);

        if ($operation->requestBody === null) {
            return empty($errors)
                ? ValidationResult::valid($version)
                : ValidationResult::invalid($errors, $version);
        }

        $contentType = $this->parseContentType($request->getHeaderLine('Content-Type'));
        $mediaType   = $operation->requestBody->content[$contentType] ?? null;

        if ($mediaType === null || $mediaType->schema === null) {
            return empty($errors)
                ? ValidationResult::valid($version)
                : ValidationResult::invalid($errors, $version);
        }

        $body   = (string) $request->getBody();
        $errors = array_merge($errors, $this->validateJsonBody($body, $mediaType->schema));

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

    public function handleFailure(ValidationResult $result, Direction $direction = Direction::Request): void
    {
        $mode = $direction === Direction::Response
            ? ($this->responseFailureMode ?? $this->failureMode)
            : $this->failureMode;

        match ($mode) {
            FailureMode::Exception => throw new ContractViolationException($result, direction: $direction),
            FailureMode::Log       => $this->logger->warning('API contract violation', [
                'version'   => $result->version,
                'errors'    => $result->errors,
                'direction' => $direction->value,
            ]),
            FailureMode::Callable  => ($this->failureCallable)($result),
        };
    }

    private function findOperation(OpenApi $spec, string $method, string $path): ?Operation
    {
        $match = $this->findPathItem($spec, $path);

        if ($match === null) {
            return null;
        }

        return $match['pathItem']->getOperations()[$method] ?? null;
    }

    /** @return array{template: string, pathItem: PathItem}|null */
    private function findPathItem(OpenApi $spec, string $path): ?array
    {
        foreach ($spec->paths as $template => $pathItem) {
            if ($pathItem instanceof PathItem && $this->pathMatches($template, $path)) {
                return ['template' => $template, 'pathItem' => $pathItem];
            }
        }

        return null;
    }

    private function pathMatches(string $template, string $path): bool
    {
        $parts   = preg_split('/(\{[^}]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '';

        foreach ($parts ?: [] as $part) {
            $pattern .= preg_match('/^\{[^}]+\}$/', $part)
                ? '[^/]+'
                : preg_quote($part, '#');
        }

        return (bool) preg_match('#^' . $pattern . '$#', $path);
    }

    /** @return string[] */
    private function validateParameters(
        Operation $operation,
        PathItem $pathItem,
        string $template,
        ServerRequestInterface $request,
    ): array {
        $errors         = [];
        $pathParameters = $this->extractPathParameters($template, $request->getUri()->getPath());

        foreach ($this->collectParameters($pathItem, $operation) as $parameter) {
            if (!$parameter->schema instanceof Schema || !in_array($parameter->in, ['query', 'path', 'header'], true)) {
                continue;
            }

            [$present, $rawValue] = $this->parameterValue($parameter, $request, $pathParameters);

            if (!$present) {
                if ($parameter->required) {
                    $errors[] = sprintf('Missing required %s parameter "%s"', $parameter->in, $parameter->name);
                }

                continue;
            }

            $value  = $this->deserializeParameterValue($parameter, $rawValue);
            $errors = array_merge($errors, $this->validateParameterValue($parameter, $value));
        }

        return $errors;
    }

    /** @return Parameter[] */
    private function collectParameters(PathItem $pathItem, Operation $operation): array
    {
        $parameters = [];

        foreach (array_merge($pathItem->parameters, $operation->parameters) as $parameter) {
            if (!$parameter instanceof Parameter) {
                continue;
            }

            $parameters[$parameter->in . ':' . $parameter->name] = $parameter;
        }

        return array_values($parameters);
    }

    /**
     * @param array<string, string> $pathParameters
     * @return array{0: bool, 1: mixed}
     */
    private function parameterValue(
        Parameter $parameter,
        ServerRequestInterface $request,
        array $pathParameters,
    ): array {
        if ($parameter->in === 'path') {
            return array_key_exists($parameter->name, $pathParameters)
                ? [true, $pathParameters[$parameter->name]]
                : [false, null];
        }

        if ($parameter->in === 'query') {
            $query = $request->getQueryParams();

            return array_key_exists($parameter->name, $query)
                ? [true, $query[$parameter->name]]
                : [false, null];
        }

        if ($parameter->in === 'header') {
            return $request->hasHeader($parameter->name)
                ? [true, $request->getHeaderLine($parameter->name)]
                : [false, null];
        }

        return [false, null];
    }

    private function deserializeParameterValue(Parameter $parameter, mixed $value): mixed
    {
        $schema = $parameter->schema;

        if (!$schema instanceof Schema) {
            return $value;
        }

        if ($schema->type !== 'array') {
            return $this->coerceParameterValue($value, $schema);
        }

        if (!is_array($value)) {
            $value = $this->splitArrayParameterValue((string) $value, $parameter);
        }

        $itemSchema = $schema->items instanceof Schema ? $schema->items : null;

        return array_map(
            fn(mixed $item): mixed => $this->coerceParameterValue($item, $itemSchema),
            $value,
        );
    }

    /** @return string[] */
    private function splitArrayParameterValue(string $value, Parameter $parameter): array
    {
        $separator = match ($parameter->style) {
            'pipeDelimited'  => '|',
            'spaceDelimited' => ' ',
            default          => ',',
        };

        if ($parameter->style === 'form' && $parameter->explode) {
            return [$value];
        }

        return $value === '' ? [] : array_map('trim', explode($separator, $value));
    }

    private function coerceParameterValue(mixed $value, ?Schema $schema): mixed
    {
        if (!$schema instanceof Schema || is_array($value)) {
            return $value;
        }

        return match ($schema->type) {
            'integer' => is_string($value) && preg_match('/^-?\d+$/', $value) ? (int) $value : $value,
            'number'  => is_string($value) && is_numeric($value) ? (float) $value : $value,
            'boolean' => $this->coerceBooleanParameterValue($value),
            'string'  => (string) $value,
            default   => $value,
        };
    }

    private function coerceBooleanParameterValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '1'  => true,
            'false', '0' => false,
            default      => $value,
        };
    }

    /** @return string[] */
    private function validateParameterValue(Parameter $parameter, mixed $value): array
    {
        $schema = $parameter->schema;

        if (!$schema instanceof Schema) {
            return [];
        }

        return array_map(
            fn(string $error): string => sprintf('%s parameter "%s": %s', $parameter->in, $parameter->name, $error),
            $this->validateJsonValue($value, $schema),
        );
    }

    /** @return array<string, string> */
    private function extractPathParameters(string $template, string $path): array
    {
        $parts   = preg_split('/(\{[^}]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $names   = [];
        $pattern = '';

        foreach ($parts ?: [] as $part) {
            if (preg_match('/^\{([^}]+)\}$/', $part, $matches)) {
                $names[] = $matches[1];
                $pattern .= '([^/]+)';
                continue;
            }

            $pattern .= preg_quote($part, '#');
        }

        if (!preg_match('#^' . $pattern . '$#', $path, $matches)) {
            return [];
        }

        $parameters = [];

        foreach ($names as $index => $name) {
            $parameters[$name] = rawurldecode($matches[$index + 1]);
        }

        return $parameters;
    }

    /** @return string[] */
    private function validateJsonBody(string $body, Schema $schema): array
    {
        if ($body === '') {
            return [];
        }

        $data = json_decode($body);

        return $this->validateJsonValue($data, $schema);
    }

    /** @return string[] */
    private function validateJsonValue(mixed $data, Schema $schema): array
    {
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
