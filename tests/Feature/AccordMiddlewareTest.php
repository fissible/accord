<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Feature;

use Fissible\Accord\AccordMiddleware;
use Fissible\Accord\ContractValidator;
use Fissible\Accord\Exception\ContractViolationException;
use Fissible\Accord\FailureMode;
use Fissible\Accord\FileSpecSource;
use Fissible\Accord\Tests\Support\RecordingLogger;
use Fissible\Accord\VersionExtractor;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AccordMiddlewareTest extends TestCase
{
    private function makeMiddleware(
        FailureMode $request,
        FailureMode $response,
        RecordingLogger $logger,
    ): AccordMiddleware {
        $validator = new ContractValidator(
            versionExtractor:    new VersionExtractor(),
            specSource:          new FileSpecSource(dirname(__DIR__) . '/Fixtures', '{base}/{version}'),
            failureMode:         $request,
            failureCallable:     null,
            logger:              $logger,
            responseFailureMode: $response,
        );

        return new AccordMiddleware($validator);
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function test_request_violation_uses_request_mode(): void
    {
        // request=Exception → a missing required query param throws before the handler runs.
        $middleware = $this->makeMiddleware(FailureMode::Exception, FailureMode::Log, new RecordingLogger());
        $request    = new ServerRequest('GET', '/v1/roster'); // missing required page + X-Client

        $this->expectException(ContractViolationException::class);
        $middleware->process($request, $this->handlerReturning(new Response(200)));
    }

    public function test_response_violation_uses_response_mode(): void
    {
        // request=Log so the (also-invalid) request only logs; response=Exception throws on the bad body.
        $logger     = new RecordingLogger();
        $middleware = $this->makeMiddleware(FailureMode::Log, FailureMode::Exception, $logger);
        $request    = (new ServerRequest('GET', '/v1/roster'))
            ->withQueryParams(['page' => '1'])
            ->withHeader('X-Client', 'abc');

        $badResponse = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\Nyholm\Psr7\Stream::create('{"not":"an-array"}')); // schema expects array

        try {
            $middleware->process($request, $this->handlerReturning($badResponse));
            $this->fail('Expected ContractViolationException for the response violation');
        } catch (ContractViolationException $e) {
            $this->assertSame('response', $e->direction->value);
        }
    }
}
