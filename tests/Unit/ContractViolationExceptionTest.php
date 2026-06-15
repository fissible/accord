<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\Direction;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\ValidationResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ContractViolationExceptionTest extends TestCase
{
    public function test_defaults_to_request_direction(): void
    {
        $e = new ContractViolationException(ValidationResult::invalid(['x'], 'v1'));

        $this->assertSame(Direction::Request, $e->direction);
    }

    public function test_carries_supplied_direction(): void
    {
        $e = new ContractViolationException(
            ValidationResult::invalid(['x'], 'v1'),
            direction: Direction::Response,
        );

        $this->assertSame(Direction::Response, $e->direction);
    }

    public function test_preserves_legacy_positional_abi(): void
    {
        $previous = new RuntimeException('root');

        $e = new ContractViolationException(
            ValidationResult::invalid(['x'], 'v1'),
            'custom message',
            42,
            $previous,
        );

        $this->assertSame('custom message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
        $this->assertSame(Direction::Request, $e->direction);
    }
}
