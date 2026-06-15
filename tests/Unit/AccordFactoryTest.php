<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\AccordFactory;
use Fissible\Accord\AccordMiddleware;
use Fissible\Accord\Drivers\Mezzio\AccordMiddleware as MezzioMiddleware;
use Fissible\Accord\Drivers\Slim\AccordMiddleware as SlimMiddleware;
use Fissible\Accord\Tests\Support\RecordingLogger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
            'spec_pattern'     => '{base}/{version}',
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

    public function test_make_accepts_array_failure_mode_without_error(): void
    {
        $middleware = AccordFactory::make(
            ['failure_mode' => ['request' => 'exception', 'response' => 'log']],
            dirname(__DIR__) . '/Fixtures',
        );

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }

    public function test_make_still_accepts_scalar_failure_mode(): void
    {
        $middleware = AccordFactory::make(
            ['failure_mode' => 'log'],
            dirname(__DIR__) . '/Fixtures',
        );

        $this->assertInstanceOf(AccordMiddleware::class, $middleware);
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    public function test_factory_logger_enables_debug_skip_logging(): void
    {
        $logger = new RecordingLogger();

        $middleware = AccordFactory::make(
            ['debug' => true, 'logger' => $logger],
            dirname(__DIR__) . '/Fixtures',
        );

        // /v99 has no spec → MissingSpec skip on the request side.
        $middleware->process(new ServerRequest('GET', '/v99/x'), $this->passthroughHandler());

        $this->assertNotEmpty($logger->records);
        $this->assertSame('missing_spec', $logger->records[0]['context']['reason']);
    }

    public function test_factory_string_false_debug_is_off(): void
    {
        $logger = new RecordingLogger();

        $middleware = AccordFactory::make(
            ['debug' => 'false', 'logger' => $logger], // string 'false' must NOT enable debug
            dirname(__DIR__) . '/Fixtures',
        );

        $middleware->process(new ServerRequest('GET', '/v99/x'), $this->passthroughHandler());

        $this->assertCount(0, $logger->records);
    }
}
