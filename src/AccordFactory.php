<?php

declare(strict_types=1);

namespace Fissible\Accord;

/**
 * Builds an AccordMiddleware from a plain config array.
 *
 * Used by the Slim and Mezzio drivers. The Laravel driver uses the service
 * provider instead, which resolves dependencies from the container.
 *
 * Config keys (all optional):
 *   failure_mode     — 'exception' | 'log' | 'callable'  (default: 'exception')
 *   failure_callable — callable|null                      (default: null)
 *   version_pattern  — regex string                       (default: '/^\/v(\d+)(?:\/|$)/')
 *   spec_pattern     — path template with {base}/{version} tokens
 *                      (default: '{base}/resources/contract/{version}.json')
 */
final class AccordFactory
{
    public static function make(array $config, string $basePath): AccordMiddleware
    {
        $versionExtractor = new VersionExtractor(
            $config['version_pattern'] ?? '/^\/v(\d+)(?:\/|$)/',
        );

        $specResolver = new SpecResolver(
            $basePath,
            $config['spec_pattern'] ?? '{base}/resources/contract/{version}.json',
        );

        $failureMode     = FailureMode::from($config['failure_mode'] ?? 'exception');
        $failureCallable = $config['failure_callable'] ?? null;

        $validator = new ContractValidator(
            versionExtractor: $versionExtractor,
            specResolver:     $specResolver,
            failureMode:      $failureMode,
            failureCallable:  $failureCallable,
        );

        return new AccordMiddleware($validator);
    }
}
