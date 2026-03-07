<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Providers;

use Fissible\Accord\AccordMiddleware;
use Fissible\Accord\ContractValidator;
use Fissible\Accord\FailureMode;
use Fissible\Accord\SpecResolver;
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

        $this->app->singleton(SpecResolver::class, fn () => new SpecResolver(
            base_path(),
            config('accord.spec_pattern'),
        ));

        $this->app->singleton(ContractValidator::class, function () {
            $failureMode     = FailureMode::from(config('accord.failure_mode'));
            $failureCallable = config('accord.failure_callable');

            if (is_array($failureCallable) || is_string($failureCallable)) {
                $failureCallable = $this->app->make(...(array) $failureCallable);
            }

            return new ContractValidator(
                versionExtractor: $this->app->make(VersionExtractor::class),
                specResolver:     $this->app->make(SpecResolver::class),
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
