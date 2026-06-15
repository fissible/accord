<?php

declare(strict_types=1);

namespace Illuminate\Support {
    if (!class_exists(ServiceProvider::class)) {
        abstract class ServiceProvider
        {
            public function __construct(
                protected mixed $app,
            ) {}

            protected function mergeConfigFrom(string $path, string $key): void {}

            protected function publishes(array $paths, ?string $group = null): void {}
        }
    }
}

namespace {
    if (!function_exists('config')) {
        function config(string $key, mixed $default = null): mixed
        {
            return \Fissible\Accord\Tests\Feature\LaravelConfig::get($key, $default);
        }
    }

    if (!function_exists('base_path')) {
        function base_path(string $path = ''): string
        {
            $base = dirname(__DIR__);

            return $path === '' ? $base : $base . '/' . ltrim($path, '/');
        }
    }

    if (!function_exists('config_path')) {
        function config_path(string $path = ''): string
        {
            $base = dirname(__DIR__) . '/config';

            return $path === '' ? $base : $base . '/' . ltrim($path, '/');
        }
    }
}

namespace Fissible\Accord\Tests\Feature {
    use Fissible\Accord\ContractValidator;
    use Fissible\Accord\Direction;
    use Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract;
    use Fissible\Accord\Drivers\Laravel\Providers\AccordServiceProvider;
    use Fissible\Accord\Exception\ContractViolationException;
    use Fissible\Accord\ValidationResult;
    use Illuminate\Contracts\Foundation\CachesConfiguration;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\AbstractLogger;
    use Psr\Log\LoggerInterface;
    use ReflectionProperty;

    class LaravelServiceProviderTest extends TestCase
    {
        protected function setUp(): void
        {
            LaravelConfig::$values = [
                'accord.failure_mode'      => 'log',
                'accord.failure_callable'  => null,
                'accord.version_pattern'   => '/^\/v(\d+)(?:\/|$)/',
                'accord.spec_source'       => 'file',
                'accord.spec_pattern'      => '{base}/Fixtures/{version}',
                'accord.spec_cache_ttl'    => 3600,
                'accord.log_channel'              => null,
                'accord.request_violation_status' => 422,
            ];
        }

        public function test_laravel_provider_injects_default_logger(): void
        {
            $logger = new RecordingLogger();
            $app    = new FakeLaravelContainer([
                LoggerInterface::class => $logger,
            ]);

            (new AccordServiceProvider($app))->register();

            $validator = $app->make(ContractValidator::class);
            $validator->handleFailure(ValidationResult::invalid(['something broke'], 'v1'));

            $this->assertCount(1, $logger->records);
            $this->assertSame('warning', $logger->records[0]['level']);
            $this->assertSame('API contract violation', $logger->records[0]['message']);
        }

        public function test_laravel_provider_uses_configured_log_channel(): void
        {
            LaravelConfig::$values['accord.log_channel'] = 'accord';

            $logger     = new RecordingLogger();
            $logManager = new FakeLogManager($logger);
            $app        = new FakeLaravelContainer([
                'log' => $logManager,
            ]);

            (new AccordServiceProvider($app))->register();

            $validator = $app->make(ContractValidator::class);
            $validator->handleFailure(ValidationResult::invalid(['something broke'], 'v1'));

            $this->assertSame('accord', $logManager->requestedChannel);
            $this->assertCount(1, $logger->records);
        }

    public function test_array_failure_mode_logs_response_violations(): void
    {
        LaravelConfig::$values['accord.failure_mode'] = ['request' => 'exception', 'response' => 'log'];

        $logger = new RecordingLogger();
        $app    = new FakeLaravelContainer([LoggerInterface::class => $logger]);

        (new AccordServiceProvider($app))->register();

        $validator = $app->make(ContractValidator::class);
        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Response);

        $this->assertCount(1, $logger->records);
    }

    public function test_array_failure_mode_throws_on_request_violations(): void
    {
        LaravelConfig::$values['accord.failure_mode'] = ['request' => 'exception', 'response' => 'log'];

        $app = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);
        (new AccordServiceProvider($app))->register();

        $validator = $app->make(ContractValidator::class);

        $this->expectException(ContractViolationException::class);
        $validator->handleFailure(ValidationResult::invalid(['bad'], 'v1'), Direction::Request);
    }

    public function test_provider_binds_middleware_with_configured_status(): void
    {
        LaravelConfig::$values['accord.request_violation_status'] = 418;

        $app = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);
        (new AccordServiceProvider($app))->register();

        $middleware = $app->make(ValidateApiContract::class);

        $this->assertSame(418, $this->readStatus($middleware));
    }

    public function test_provider_casts_string_status_and_guards_non_4xx(): void
    {
        $app = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);

        LaravelConfig::$values['accord.request_violation_status'] = '418';
        (new AccordServiceProvider($app))->register();
        $this->assertSame(418, $this->readStatus($app->make(ValidateApiContract::class)));

        // Out-of-4xx falls back to 422. Fresh container so the singleton is rebuilt.
        $app2 = new FakeLaravelContainer([LoggerInterface::class => new RecordingLogger()]);
        LaravelConfig::$values['accord.request_violation_status'] = 500;
        (new AccordServiceProvider($app2))->register();
        $this->assertSame(422, $this->readStatus($app2->make(ValidateApiContract::class)));
    }

    private function readStatus(ValidateApiContract $middleware): int
    {
        $property = new ReflectionProperty(ValidateApiContract::class, 'requestViolationStatus');

        return $property->getValue($middleware);
    }
    }

    final class LaravelConfig
    {
        /** @var array<string, mixed> */
        public static array $values = [];

        public static function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, self::$values)
                ? self::$values[$key]
                : $default;
        }
    }

    /**
     * Minimal container double. Implements CachesConfiguration (reporting the config
     * as "cached") so the real Illuminate\Support\ServiceProvider::mergeConfigFrom —
     * pulled in once illuminate/http became a dev dependency — short-circuits instead
     * of requiring the real config file. Provider config reads go through the global
     * config() stub (LaravelConfig) below, not this container.
     */
    final class FakeLaravelContainer implements CachesConfiguration
    {
        /** @var array<string, mixed> */
        private array $instances;

        /** @var array<string, callable(): mixed> */
        private array $singletons = [];

        /** @param array<string, mixed> $instances */
        public function __construct(array $instances = [])
        {
            $this->instances = $instances;
        }

        public function configurationIsCached(): bool
        {
            return true;
        }

        public function getCachedConfigPath(): string
        {
            return '';
        }

        public function getCachedServicesPath(): string
        {
            return '';
        }

        public function singleton(string $abstract, callable $factory): void
        {
            $this->singletons[$abstract] = $factory;
        }

        public function make(string $abstract): mixed
        {
            if (array_key_exists($abstract, $this->instances)) {
                return $this->instances[$abstract];
            }

            if (array_key_exists($abstract, $this->singletons)) {
                return $this->instances[$abstract] = ($this->singletons[$abstract])();
            }

            throw new \RuntimeException("Nothing bound for {$abstract}");
        }
    }

    final class FakeLogManager
    {
        public ?string $requestedChannel = null;

        public function __construct(
            private readonly LoggerInterface $logger,
        ) {}

        public function channel(string $channel): LoggerInterface
        {
            $this->requestedChannel = $channel;

            return $this->logger;
        }
    }

    final class RecordingLogger extends AbstractLogger
    {
        /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->records[] = [
                'level'   => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    }
}
