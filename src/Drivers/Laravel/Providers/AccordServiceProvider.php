<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Providers;

use Fissible\Accord\AccordMiddleware;
use Fissible\Accord\ContractValidator;
use Fissible\Accord\FailureMode;
use Fissible\Accord\FileSpecSource;
use Fissible\Accord\SpecSourceInterface;
use Fissible\Accord\UrlSpecSource;
use Fissible\Accord\VersionExtractor;
use Illuminate\Support\ServiceProvider;

class AccordServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/accord.php',
            'accord',
        );

        $this->app->singleton(VersionExtractor::class, fn () => new VersionExtractor(
            config('accord.version_pattern'),
        ));

        $this->app->singleton(SpecSourceInterface::class, function () {
            $type    = config('accord.spec_source', 'file');
            $pattern = config('accord.spec_pattern');

            if ($type === 'url') {
                return new UrlSpecSource(
                    pattern: $pattern,
                    ttl:     (int) config('accord.spec_cache_ttl', 3600),
                );
            }

            return new FileSpecSource(base_path(), $pattern);
        });

        $this->app->singleton(ContractValidator::class, function () {
            $failureMode     = FailureMode::from(config('accord.failure_mode'));
            $failureCallable = config('accord.failure_callable');

            if (is_array($failureCallable) || is_string($failureCallable)) {
                $failureCallable = $this->app->make(...(array) $failureCallable);
            }

            return new ContractValidator(
                versionExtractor: $this->app->make(VersionExtractor::class),
                specSource:       $this->app->make(SpecSourceInterface::class),
                failureMode:      $failureMode,
                failureCallable:  $failureCallable,
            );
        });

        $this->app->singleton(AccordMiddleware::class, fn () => new AccordMiddleware(
            $this->app->make(ContractValidator::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/accord.php' => config_path('accord.php'),
        ], 'accord-config');
    }
}
