<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Slim;

use Fissible\Accord\AccordMiddleware as CoreMiddleware;
use Fissible\Accord\ContractValidator;

/**
 * Slim driver — thin factory for adding Accord to a Slim app.
 *
 * Slim natively supports PSR-15, so the core AccordMiddleware works as-is.
 *
 * Usage:
 *   $app->add(new \Fissible\Accord\AccordMiddleware($validator));
 *
 * Or via this factory:
 *   $app->add(AccordMiddleware::fromConfig(['spec_pattern' => ...]));
 */
class AccordMiddleware extends CoreMiddleware
{
    public static function fromConfig(array $config, string $basePath): CoreMiddleware
    {
        // TODO: build ContractValidator from $config and return new CoreMiddleware
        throw new \LogicException('Not yet implemented.');
    }
}
