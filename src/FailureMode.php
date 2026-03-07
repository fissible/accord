<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum FailureMode: string
{
    case Exception = 'exception';
    case Log       = 'log';
    case Callable  = 'callable';
}
