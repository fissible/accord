<?php

declare(strict_types=1);

namespace Fissible\Accord;

final class ValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $version,
        public readonly array $errors = [],
        public readonly ?SkipReason $skipReason = null,
    ) {}

    public static function valid(string $version): self
    {
        return new self(valid: true, version: $version);
    }

    public static function invalid(array $errors, string $version): self
    {
        return new self(valid: false, version: $version, errors: $errors);
    }

    public static function skipped(SkipReason $reason, string $version): self
    {
        return new self(valid: true, version: $version, skipReason: $reason);
    }

    /** True when the request/response was actually checked (a pass OR a genuine failure). */
    public function wasValidated(): bool
    {
        return $this->skipReason === null;
    }

    /** True when validation was skipped (fail-open); skips always have valid === true. */
    public function wasSkipped(): bool
    {
        return $this->skipReason !== null;
    }
}
