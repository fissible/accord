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
    use Fissible\Accord\Drivers\Laravel\Providers\AccordServiceProvider;
    use Fissible\Accord\ValidationResult;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\AbstractLogger;
    use Psr\Log\LoggerInterface;

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
                'accord.log_channel'       => null,
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

    final class FakeLaravelContainer
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
