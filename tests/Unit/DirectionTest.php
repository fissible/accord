<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\Direction;
use PHPUnit\Framework\TestCase;

class DirectionTest extends TestCase
{
    public function test_backing_values_are_stable_strings(): void
    {
        $this->assertSame('request', Direction::Request->value);
        $this->assertSame('response', Direction::Response->value);
    }
}
