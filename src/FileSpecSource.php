<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;

/**
 * Loads OpenAPI specs from the local filesystem.
 *
 * The pattern uses {base} and {version} tokens and should NOT include a file
 * extension — FileSpecSource tries .yaml, .yml, and .json in that order.
 * If your pattern already includes an extension it is used as-is.
 *
 * Default pattern: {base}/resources/openapi/{version}
 */
class FileSpecSource implements SpecSourceInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $pattern = '{base}/resources/openapi/{version}',
    ) {}

    public function load(string $version): ?OpenApi
    {
        $path = $this->findPath($version);

        if ($path === null) {
            return null;
        }

        return $this->isYaml($path)
            ? Reader::readFromYamlFile($path)
            : Reader::readFromJsonFile($path);
    }

    public function exists(string $version): bool
    {
        return $this->findPath($version) !== null;
    }

    public function resolvedPath(string $version): ?string
    {
        return $this->findPath($version);
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
