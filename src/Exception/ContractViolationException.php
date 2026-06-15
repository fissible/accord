<?php

declare(strict_types=1);

namespace Fissible\Accord\Exception;

use Fissible\Accord\Direction;
use Fissible\Accord\ValidationResult;
use RuntimeException;

class ContractViolationException extends RuntimeException
{
    public function __construct(
        public readonly ValidationResult $result,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly Direction $direction = Direction::Request,
    ) {
        parent::__construct(
            $message ?: sprintf(
                'API contract violation for version %s: %s',
                $result->version,
                implode('; ', $result->errors),
            ),
            $code,
            $previous,
        );
    }
}
