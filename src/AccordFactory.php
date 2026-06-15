<?php

declare(strict_types=1);

namespace Fissible\Accord;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds an AccordMiddleware from a plain config array.
 *
 * Used by the Slim and Mezzio drivers. The Laravel driver uses the service
 * provider instead, which resolves dependencies from the container.
 *
 * Config keys (all optional):
 *   failure_mode     — 'exception' | 'log' | 'callable', or an array with separate
 *                      'request' and 'response' modes                (default: 'exception')
 *   failure_callable — callable|null                      (default: null)
 *   version_pattern  — regex string                       (default: '/^\/v(\d+)(?:\/|$)/')
 *   spec_source      — 'file' | 'url'                     (default: 'file')
 *   spec_pattern     — path/URL template with {base} and {version} tokens
 *                      file default: '{base}/resources/openapi/{version}'
 *                      url example:  'https://api.example.com/openapi/{version}.yaml'
 *   spec_cache_ttl   — PSR-16 cache TTL in seconds for URL source (default: 3600)
 *   debug            — bool; log skipped (non-validated) requests/responses and why.
 *                      Requires a logger to produce output (see below) (default: false)
 *   logger           — Psr\Log\LoggerInterface|null; where debug skip logs are written;
 *                      without it, debug logging has nowhere to go    (default: NullLogger)
 *   exclude          — string[] of glob patterns; matched routes skip all validation (default: [])
 *   validate_responses — bool; validate responses (requests always validated)  (default: true)
 *   response_sample_rate — float 0.0–1.0; fraction of responses to validate, clamped (default: 1.0)
 */
final class AccordFactory
{
    public static function make(array $config, string $basePath): AccordMiddleware
    {
        $versionExtractor = new VersionExtractor(
            $config['version_pattern'] ?? '/^\/v(\d+)(?:\/|$)/',
        );

        $specSource = self::makeSpecSource($config, $basePath);

        [$requestMode, $responseMode] = FailureMode::resolvePair($config['failure_mode'] ?? 'exception');
        $failureCallable = $config['failure_callable'] ?? null;

        $logger = ($config['logger'] ?? null) instanceof LoggerInterface
            ? $config['logger']
            : new NullLogger();

        $validator = new ContractValidator(
            versionExtractor:    $versionExtractor,
            specSource:          $specSource,
            failureMode:         $requestMode,
            failureCallable:     $failureCallable,
            logger:              $logger,
            responseFailureMode: $responseMode,
            debug:               filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN),
            runtimeOptions:      new RuntimeOptions(
                excludedPaths:      $config['exclude'] ?? [],
                validateResponses:  filter_var($config['validate_responses'] ?? true, FILTER_VALIDATE_BOOLEAN),
                responseSampleRate: (float) ($config['response_sample_rate'] ?? 1.0),
            ),
        );

        return new AccordMiddleware($validator);
    }

    private static function makeSpecSource(array $config, string $basePath): SpecSourceInterface
    {
        $type = $config['spec_source'] ?? 'file';

        if ($type === 'url') {
            return new UrlSpecSource(
                $config['spec_pattern'] ?? throw new \InvalidArgumentException(
                    'spec_pattern is required when spec_source is "url"',
                ),
                $config['spec_cache'] ?? null,
                (int) ($config['spec_cache_ttl'] ?? 3600),
            );
        }

        return new FileSpecSource(
            $basePath,
            $config['spec_pattern'] ?? '{base}/resources/openapi/{version}',
        );
    }
}
