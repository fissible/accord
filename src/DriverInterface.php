<?php

declare(strict_types=1);

namespace Fissible\Accord;

/**
 * Extension point for community drivers.
 *
 * Implement this interface to integrate fissible/accord with any framework
 * not covered by the bundled first-party drivers (Laravel, Slim, Mezzio).
 */
interface DriverInterface
{
    /**
     * Resolve the absolute path to the OpenAPI spec file for the given version.
     *
     * @param  string $version  e.g. "v1", "v2"
     */
    public function resolveSpecPath(string $version): string;

    public function getFailureMode(): FailureMode;

    /**
     * Return the callable invoked when failure mode is FailureMode::Callable.
     *
     * The callable receives a ValidationResult as its sole argument.
     *
     * @return callable(ValidationResult): void|null
     */
    public function getFailureCallable(): ?callable;
}
