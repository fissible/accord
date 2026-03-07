<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Mezzio;

use Fissible\Accord\AccordFactory;
use Fissible\Accord\AccordMiddleware as CoreMiddleware;

/**
 * Mezzio (Laminas) driver — factory for adding Accord to a Mezzio pipeline.
 *
 * Mezzio natively supports PSR-15, so the core AccordMiddleware is used directly.
 *
 * Usage in config/pipeline.php:
 *   $app->pipe(AccordMiddleware::fromConfig([
 *       'failure_mode'   => 'log',
 *       'spec_pattern'   => '{base}/openapi/{version}.json',
 *   ], __DIR__));
 *
 * Or register via a factory in your container config:
 *   AccordMiddleware::class => fn() => AccordMiddleware::fromConfig($config, $basePath),
 */
class AccordMiddleware extends CoreMiddleware
{
    public static function fromConfig(array $config, string $basePath): CoreMiddleware
    {
        return AccordFactory::make($config, $basePath);
    }
}
