<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\SkipReason;
use PHPUnit\Framework\TestCase;

class SkipReasonTest extends TestCase
{
    public function test_backing_values_are_stable(): void
    {
        $this->assertSame('unversioned', SkipReason::Unversioned->value);
        $this->assertSame('missing_spec', SkipReason::MissingSpec->value);
        $this->assertSame('unmatched_operation', SkipReason::UnmatchedOperation->value);
        $this->assertSame('missing_request_schema', SkipReason::MissingRequestSchema->value);
        $this->assertSame('missing_response_schema', SkipReason::MissingResponseSchema->value);
        $this->assertSame('unsupported_media_type', SkipReason::UnsupportedMediaType->value);
    }
}
