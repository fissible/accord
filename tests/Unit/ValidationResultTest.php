<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\ValidationResult;
use PHPUnit\Framework\TestCase;

class ValidationResultTest extends TestCase
{
    public function test_valid_result_has_no_errors(): void
    {
        $result = ValidationResult::valid('v1');

        $this->assertTrue($result->valid);
        $this->assertSame('v1', $result->version);
        $this->assertEmpty($result->errors);
    }

    public function test_invalid_result_carries_errors(): void
    {
        $errors = ['id must be integer', 'name is required'];
        $result = ValidationResult::invalid($errors, 'v2');

        $this->assertFalse($result->valid);
        $this->assertSame('v2', $result->version);
        $this->assertSame($errors, $result->errors);
    }

    public function test_result_is_immutable(): void
    {
        $result = ValidationResult::valid('v1');

        $this->expectException(\Error::class);
        $result->valid = false; // @phpstan-ignore-line
    }
}
