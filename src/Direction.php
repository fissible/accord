<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum Direction: string
{
    case Request  = 'request';
    case Response = 'response';
}
