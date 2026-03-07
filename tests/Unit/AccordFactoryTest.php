<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\AccordFactory;
use Fissible\Accord\AccordMiddleware;
use Fissible\Accord\Drivers\Mezzio\AccordMiddleware as MezzioMiddleware;
use Fissible\Accord\Drivers\Slim\AccordMiddleware as SlimMiddleware;
use PHPUnit\Framework\TestCase;

class AccordFactoryTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = dirname(__DIR__) . '/Fixtures';
    }

    public function test_make_returns_accord_middleware(): void
    {
        $middleware = AccordFactory::make([], $this->basePath);

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }

    public function test_make_uses_defaults_when_config_is_empty(): void
    {
        // Should not throw — all config keys are optional
        $middleware = AccordFactory::make([], $this->basePath);

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }

    public function test_make_accepts_all_config_keys(): void
    {
        $middleware = AccordFactory::make([
            'failure_mode'     => 'log',
            'failure_callable' => null,
            'version_pattern'  => '/^\/api\/v(\d+)\//',
            'spec_pattern'     => '{base}/{version}.json',
        ], $this->basePath);

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }

    public function test_make_throws_on_invalid_failure_mode(): void
    {
        $this->expectException(\ValueError::class);

        AccordFactory::make(['failure_mode' => 'invalid'], $this->basePath);
    }

    public function test_slim_from_config_returns_core_middleware(): void
    {
        $middleware = SlimMiddleware::fromConfig([], $this->basePath);

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }

    public function test_mezzio_from_config_returns_core_middleware(): void
    {
        $middleware = MezzioMiddleware::fromConfig([], $this->basePath);

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }
}
