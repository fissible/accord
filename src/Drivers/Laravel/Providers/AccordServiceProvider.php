<?php

declare(strict_types=1);

namespace Fissible\Accord\Drivers\Laravel\Providers;

use Fissible\Accord\AccordMiddleware;
use Fissible\Accord\ContractValidator;
use Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract;
use Fissible\Accord\FailureMode;
use Fissible\Accord\RuntimeOptions;
use Fissible\Accord\FileSpecSource;
use Fissible\Accord\SpecSourceInterface;
use Fissible\Accord\UrlSpecSource;
use Fissible\Accord\VersionExtractor;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

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
            $cache   = $this->resolveSpecCache();
            $ttl     = (int) config('accord.spec_cache_ttl', 3600);

            if ($type === 'url') {
                return new UrlSpecSource(pattern: $pattern, cache: $cache, ttl: $ttl);
            }

            return new FileSpecSource(base_path(), $pattern, $cache, $ttl);
        });

        $this->app->singleton(ContractValidator::class, function () {
            [$requestMode, $responseMode] = FailureMode::resolvePair(config('accord.failure_mode'));
            $failureCallable = config('accord.failure_callable');

            if (is_array($failureCallable) || is_string($failureCallable)) {
                $failureCallable = $this->app->make(...(array) $failureCallable);
            }

            return new ContractValidator(
                versionExtractor:    $this->app->make(VersionExtractor::class),
                specSource:          $this->app->make(SpecSourceInterface::class),
                failureMode:         $requestMode,
                failureCallable:     $failureCallable,
                logger:              $this->resolveLogger(),
                responseFailureMode: $responseMode,
                debug:               (bool) config('accord.debug', false),
                runtimeOptions:      new RuntimeOptions(
                    excludedPaths:      config('accord.exclude', []),
                    validateResponses:  (bool) config('accord.validate_responses', true),
                    responseSampleRate: (float) config('accord.response_sample_rate', 1.0),
                ),
            );
        });

        $this->app->singleton(AccordMiddleware::class, fn () => new AccordMiddleware(
            $this->app->make(ContractValidator::class),
        ));

        $this->app->singleton(ValidateApiContract::class, fn () => new ValidateApiContract(
            $this->app->make(ContractValidator::class),
            $this->resolveRequestViolationStatus(),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/accord.php' => config_path('accord.php'),
        ], 'accord-config');
    }

    private function resolveRequestViolationStatus(): int
    {
        $status = (int) config('accord.request_violation_status', 422);

        return ($status >= 400 && $status <= 499) ? $status : 422;
    }

    private function resolveLogger(): LoggerInterface
    {
        $channel = config('accord.log_channel');

        if (is_string($channel) && $channel !== '') {
            return $this->app->make('log')->channel($channel);
        }

        return $this->app->make(LoggerInterface::class);
    }

    private function resolveSpecCache(): ?CacheInterface
    {
        $store = config('accord.spec_cache');

        if ($store === null || $store === false || $store === '') {
            return null;
        }

        return $store === true
            ? $this->app->make('cache')->store()        // default store (NO argument — avoids store('1'))
            : $this->app->make('cache')->store($store);  // named store
    }
}
