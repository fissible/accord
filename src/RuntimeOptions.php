<?php

declare(strict_types=1);

namespace Fissible\Accord;

use Closure;

/**
 * Runtime validation policy: which routes to skip, whether to validate responses,
 * and what fraction of responses to sample. Permissive defaults reproduce
 * "validate everything" behavior.
 */
final class RuntimeOptions
{
    private readonly float $responseSampleRate;

    /** @var Closure(): float */
    private readonly Closure $sampler;

    /**
     * @param string[]      $excludedPaths      glob patterns; '*' matches any chars including '/'
     * @param bool          $validateResponses  validate responses (requests are always validated unless excluded)
     * @param float         $responseSampleRate fraction 0.0–1.0 of responses to validate (clamped, never throws)
     * @param Closure(): float|null $sampler    test seam: a draw source returning a float in [0,1]
     */
    public function __construct(
        private readonly array $excludedPaths = [],
        private readonly bool $validateResponses = true,
        float $responseSampleRate = 1.0,
        ?Closure $sampler = null,
    ) {
        $this->responseSampleRate = max(0.0, min(1.0, $responseSampleRate));
        $this->sampler            = $sampler ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    public function isExcluded(string $path): bool
    {
        foreach ($this->excludedPaths as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    public function validatesResponses(): bool
    {
        return $this->validateResponses;
    }

    public function shouldSampleResponse(): bool
    {
        if ($this->responseSampleRate >= 1.0) {
            return true;
        }

        if ($this->responseSampleRate <= 0.0) {
            return false;
        }

        return ($this->sampler)() < $this->responseSampleRate;
    }
}
