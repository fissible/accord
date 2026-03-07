<?php

declare(strict_types=1);

namespace Fissible\Accord;

final class ValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $version,
        public readonly array $errors = [],
    ) {}

    public static function valid(string $version): self
    {
        return new self(valid: true, version: $version);
    }

    public static function invalid(array $errors, string $version): self
    {
        return new self(valid: false, version: $version, errors: $errors);
    }
}
