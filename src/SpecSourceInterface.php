<?php

declare(strict_types=1);

namespace Fissible\Accord;

use cebe\openapi\spec\OpenApi;

/**
 * Locates and loads a parsed OpenAPI spec for a given API version.
 *
 * Implement this interface to teach Accord where to find specs —
 * local files, remote URLs, a registry, or any other source.
 */
interface SpecSourceInterface
{
    /**
     * Load and return the parsed OpenAPI spec for the given version.
     *
     * Returns null if no spec is available for that version, which Accord
     * treats as "no constraint" — requests and responses pass unchecked.
     *
     * @param string $version  e.g. "v1", "v2"
     */
    public function load(string $version): ?OpenApi;

    /**
     * Return true if a spec exists for the given version without fully loading it.
     * Used by CLI tools to report spec coverage. May delegate to load() if a
     * cheap existence check is not possible (e.g. URL sources).
     */
    public function exists(string $version): bool;
}
