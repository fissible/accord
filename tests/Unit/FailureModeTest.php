<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\FailureMode;
use PHPUnit\Framework\TestCase;
use ValueError;

class FailureModeTest extends TestCase
{
    public function test_scalar_applies_to_both_directions(): void
    {
        $this->assertSame([FailureMode::Log, FailureMode::Log], FailureMode::resolvePair('log'));
    }

    public function test_full_array_maps_each_direction(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Log],
            FailureMode::resolvePair(['request' => 'exception', 'response' => 'log']),
        );
    }

    public function test_missing_response_key_falls_back_to_request(): void
    {
        $this->assertSame(
            [FailureMode::Log, FailureMode::Log],
            FailureMode::resolvePair(['request' => 'log']),
        );
    }

    public function test_missing_request_key_falls_back_to_exception_default(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Log],
            FailureMode::resolvePair(['response' => 'log']),
        );
    }

    public function test_empty_array_falls_back_to_exception(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Exception],
            FailureMode::resolvePair([]),
        );
    }

    public function test_null_falls_back_to_exception(): void
    {
        $this->assertSame(
            [FailureMode::Exception, FailureMode::Exception],
            FailureMode::resolvePair(null),
        );
    }

    public function test_unknown_scalar_throws(): void
    {
        $this->expectException(ValueError::class);
        FailureMode::resolvePair('bogus');
    }

    public function test_blank_scalar_throws(): void
    {
        $this->expectException(ValueError::class);
        FailureMode::resolvePair('');
    }

    public function test_present_but_unknown_array_value_throws(): void
    {
        $this->expectException(ValueError::class);
        FailureMode::resolvePair(['request' => 'nope']);
    }
}
