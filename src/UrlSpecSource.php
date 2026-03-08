<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Psr\SimpleCache\CacheInterface;

/**
 * Loads OpenAPI specs from a remote URL.
 *
 * The pattern uses a {version} token, e.g.:
 *   https://api.example.com/openapi/{version}.yaml
 *
 * In-process caching is handled by ContractValidator. The optional PSR-16
 * cache persists specs across process restarts — useful in serverless or
 * short-lived process environments. In traditional PHP-FPM deployments the
 * in-process cache is sufficient and no external cache is needed.
 *
 * Format detection: checks the URL path extension (.yaml/.yml → YAML,
 * .json → JSON). If no extension is present, JSON is assumed.
 */
class UrlSpecSource implements SpecSourceInterface
{
    public function __construct(
        private readonly string $pattern,
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 3600,
    ) {}

    public function load(string $version): ?OpenApi
    {
        $url      = $this->resolveUrl($version);
        $cacheKey = 'fissible.accord.spec.' . hash('xxh32', $url);

        $content = $this->cache?->get($cacheKey);

        if ($content === null) {
            $content = $this->fetchContent($url);

            if ($content === null) {
                return null;
            }

            $this->cache?->set($cacheKey, $content, $this->ttl);
        }

        return $this->parse($content, $url);
    }

    public function exists(string $version): bool
    {
        return $this->load($version) !== null;
    }

    public function resolveUrl(string $version): string
    {
        return str_replace('{version}', $version, $this->pattern);
    }

    protected function fetchContent(string $url): ?string
    {
        $content = @file_get_contents($url);

        return $content === false ? null : $content;
    }

    private function parse(string $content, string $url): ?OpenApi
    {
        try {
            return $this->isYaml($url)
                ? Reader::readFromYaml($content)
                : Reader::readFromJson($content);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isYaml(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return str_ends_with($path, '.yaml') || str_ends_with($path, '.yml');
    }
}
