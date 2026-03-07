<?php

declare(strict_types=1);

namespace Fissible\Accord;

class SpecResolver
{
    /**
     * @param string $basePath  Absolute path to the project root.
     * @param string $pattern   Tokens: {base}, {version}. Defaults to Laravel-style resources path.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $pattern = '{base}/resources/contract/{version}.json',
    ) {}

    public function resolve(string $version): string
    {
        return str_replace(
            ['{base}', '{version}'],
            [$this->basePath, $version],
            $this->pattern,
        );
    }

    public function exists(string $version): bool
    {
        return file_exists($this->resolve($version));
    }
}
