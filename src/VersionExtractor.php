<?php

declare(strict_types=1);

namespace Fissible\Accord;

use Psr\Http\Message\ServerRequestInterface;

class VersionExtractor
{
    public function __construct(
        private readonly string $pattern = '/^\/v(\d+)(?:\/|$)/',
    ) {}

    /**
     * Extract the API version from the request URI path.
     *
     * Returns null if no version segment is found, which is treated as
     * "no spec constraint" by the validator.
     */
    public function extract(ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();

        if (preg_match($this->pattern, $path, $matches)) {
            return 'v' . $matches[1];
        }

        return null;
    }
}
