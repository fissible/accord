<?php

declare(strict_types=1);

namespace Fissible\Accord\Exception;

use Fissible\Accord\ValidationResult;
use RuntimeException;

class ContractViolationException extends RuntimeException
{
    public function __construct(
        public readonly ValidationResult $result,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
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
