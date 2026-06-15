<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\RuntimeOptions;
use PHPUnit\Framework\TestCase;

class RuntimeOptionsTest extends TestCase
{
    public function test_exact_path_is_excluded(): void
    {
        $this->assertTrue((new RuntimeOptions(['/v2/health']))->isExcluded('/v2/health'));
    }

    public function test_non_matching_path_is_not_excluded(): void
    {
        $this->assertFalse((new RuntimeOptions(['/v2/health']))->isExcluded('/v2/users'));
    }

    public function test_empty_exclusions_never_match(): void
    {
        $this->assertFalse((new RuntimeOptions())->isExcluded('/v2/anything'));
    }

    public function test_star_matches_across_slashes(): void
    {
        $options = new RuntimeOptions(['/v2/internal/*']);

        $this->assertTrue($options->isExcluded('/v2/internal/a/b/c'));
        $this->assertFalse($options->isExcluded('/v2/public/x'));
    }

    public function test_leading_star_matches_any_version(): void
    {
        $this->assertTrue((new RuntimeOptions(['*/metrics']))->isExcluded('/v9/metrics'));
    }

    public function test_validates_responses_reflects_flag(): void
    {
        $this->assertTrue((new RuntimeOptions(validateResponses: true))->validatesResponses());
        $this->assertFalse((new RuntimeOptions(validateResponses: false))->validatesResponses());
    }

    public function test_rate_above_one_is_clamped_to_always_sample(): void
    {
        $options = new RuntimeOptions(
            responseSampleRate: 2.0,
            sampler: fn (): float => throw new \RuntimeException('sampler must not be consulted at rate 1.0'),
        );

        $this->assertTrue($options->shouldSampleResponse());
    }

    public function test_rate_below_zero_is_clamped_to_never_sample(): void
    {
        $options = new RuntimeOptions(
            responseSampleRate: -1.0,
            sampler: fn (): float => throw new \RuntimeException('sampler must not be consulted at rate 0.0'),
        );

        $this->assertFalse($options->shouldSampleResponse());
    }

    public function test_draw_below_rate_is_sampled_in(): void
    {
        $options = new RuntimeOptions(responseSampleRate: 0.5, sampler: fn (): float => 0.3);

        $this->assertTrue($options->shouldSampleResponse());
    }

    public function test_draw_at_or_above_rate_is_sampled_out(): void
    {
        $options = new RuntimeOptions(responseSampleRate: 0.5, sampler: fn (): float => 0.7);

        $this->assertFalse($options->shouldSampleResponse());
    }
}
