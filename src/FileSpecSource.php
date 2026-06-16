<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Psr\SimpleCache\CacheInterface;

/**
 * Loads OpenAPI specs from the local filesystem.
 *
 * The pattern uses {base} and {version} tokens and should NOT include a file
 * extension — FileSpecSource tries .yaml, .yml, and .json in that order.
 * If your pattern already includes an extension it is used as-is.
 *
 * Default pattern: {base}/resources/openapi/{version}
 *
 * Pass an optional PSR-16 cache to persist the PARSED spec across processes
 * (PHP-FPM): on a hit the spec is rehydrated from cached JSON, skipping the
 * (slow) YAML parse. The cache key includes the file's mtime, so a redeployed
 * spec auto-invalidates. In-process caching is handled by ContractValidator.
 */
class FileSpecSource implements SpecSourceInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $pattern = '{base}/resources/openapi/{version}',
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 3600,
    ) {}

    public function load(string $version): ?OpenApi
    {
        $path = $this->findPath($version);

        if ($path === null) {
            return null;
        }

        if ($this->cache === null) {
            return $this->parse($path);
        }

        $cacheKey = sprintf('fissible.accord.spec.file.%s.%d', hash('xxh32', $path), @filemtime($path) ?: 0);

        try {
            $cached = $this->cache->get($cacheKey);
        } catch (\Throwable) {
            $cached = null; // cache get failure → treat as miss
        }

        if (is_string($cached)) {
            try {
                return Reader::readFromJson($cached);
            } catch (\Throwable) {
                // unrehydratable (invalid-JSON) cache entry → fall through to re-parse
            }
        }

        $spec = $this->parse($path);
        $json = json_encode($spec->getSerializableData());

        if (is_string($json)) {
            try {
                $this->cache->set($cacheKey, $json, $this->ttl);
            } catch (\Throwable) {
                // caching is best-effort; never break spec loading
            }
        }

        return $spec;
    }

    public function exists(string $version): bool
    {
        return $this->findPath($version) !== null;
    }

    public function resolvedPath(string $version): ?string
    {
        return $this->findPath($version);
    }

    private function parse(string $path): OpenApi
    {
        return $this->isYaml($path)
            ? Reader::readFromYamlFile($path)
            : Reader::readFromJsonFile($path);
    }

    private function findPath(string $version): ?string
    {
        $resolved = str_replace(
            ['{base}', '{version}'],
            [$this->basePath, $version],
            $this->pattern,
        );

        // Pattern already includes a valid extension or exact path
        if (file_exists($resolved)) {
            return $resolved;
        }

        // Try extensions in preference order (YAML first — the preferred hand-authored format)
        foreach (['.yaml', '.yml', '.json'] as $ext) {
            if (file_exists($resolved . $ext)) {
                return $resolved . $ext;
            }
        }

        return null;
    }

    private function isYaml(string $path): bool
    {
        return str_ends_with($path, '.yaml') || str_ends_with($path, '.yml');
    }
}
