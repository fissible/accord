<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum SkipReason: string
{
    case Unversioned                = 'unversioned';
    case MissingSpec                = 'missing_spec';
    case UnmatchedOperation         = 'unmatched_operation';
    case MissingRequestSchema       = 'missing_request_schema';
    case MissingResponseSchema      = 'missing_response_schema';
    case UnsupportedMediaType       = 'unsupported_media_type';
    case Excluded                   = 'excluded';
    case ResponseValidationDisabled = 'response_validation_disabled';
    case NotSampled                 = 'not_sampled';
}
