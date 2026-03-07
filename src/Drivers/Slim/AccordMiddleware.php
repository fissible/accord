<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Slim;

use Fissible\Accord\AccordFactory;
use Fissible\Accord\AccordMiddleware as CoreMiddleware;

/**
 * Slim driver — factory for adding Accord to a Slim app.
 *
 * Slim natively supports PSR-15, so the core AccordMiddleware is used directly.
 *
 * Usage:
 *   $app->add(AccordMiddleware::fromConfig([
 *       'failure_mode'   => 'log',
 *       'spec_pattern'   => '{base}/openapi/{version}.json',
 *   ], __DIR__));
 *
 * Or without this factory, binding directly to the core:
 *   $app->add(new \Fissible\Accord\AccordMiddleware($validator));
 */
class AccordMiddleware extends CoreMiddleware
{
    public static function fromConfig(array $config, string $basePath): CoreMiddleware
    {
        return AccordFactory::make($config, $basePath);
    }
}
