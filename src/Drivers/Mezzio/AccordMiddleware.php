<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Mezzio;

use Fissible\Accord\AccordMiddleware as CoreMiddleware;
use Fissible\Accord\ContractValidator;

/**
 * Mezzio (Laminas) driver — thin factory for adding Accord to a Mezzio pipeline.
 *
 * Mezzio natively supports PSR-15, so the core AccordMiddleware works as-is.
 *
 * Usage in config/pipeline.php:
 *   $app->pipe(\Fissible\Accord\AccordMiddleware::class);
 *
 * Or via this factory:
 *   $app->pipe(AccordMiddleware::fromConfig([...]));
 */
class AccordMiddleware extends CoreMiddleware
{
    public static function fromConfig(array $config, string $basePath): CoreMiddleware
    {
        // TODO: build ContractValidator from $config and return new CoreMiddleware
        throw new \LogicException('Not yet implemented.');
    }
}
