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
 *   spec_source      — 'file' | 'url'                     (default: 'file')
 *   spec_pattern     — path/URL template with {base} and {version} tokens
 *                      file default: '{base}/resources/openapi/{version}'
 *                      url example:  'https://api.example.com/openapi/{version}.yaml'
 *   spec_cache_ttl   — PSR-16 cache TTL in seconds for URL source (default: 3600)
 */
final class AccordFactory
{
    public static function make(array $config, string $basePath): AccordMiddleware
    {
        $versionExtractor = new VersionExtractor(
            $config['version_pattern'] ?? '/^\/v(\d+)(?:\/|$)/',
        );

        $specSource = self::makeSpecSource($config, $basePath);

        $failureMode     = FailureMode::from($config['failure_mode'] ?? 'exception');
        $failureCallable = $config['failure_callable'] ?? null;

        $validator = new ContractValidator(
            versionExtractor: $versionExtractor,
            specSource:       $specSource,
            failureMode:      $failureMode,
            failureCallable:  $failureCallable,
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
