<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\VersionExtractor;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionExtractorTest extends TestCase
{
    private VersionExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new VersionExtractor();
    }

    #[DataProvider('versionedPaths')]
    public function test_extracts_version_from_uri_path(string $path, string $expected): void
    {
        $request = new ServerRequest('GET', $path);
        $this->assertSame($expected, $this->extractor->extract($request));
    }

    public static function versionedPaths(): array
    {
        return [
            'top-level resource'      => ['/v1/users',          'v1'],
            'nested resource'         => ['/v2/orders/123',     'v2'],
            'deeply nested'           => ['/v3/a/b/c',          'v3'],
            'version only with slash' => ['/v1/',               'v1'],
            'double-digit version'    => ['/v10/things',        'v10'],
        ];
    }

    #[DataProvider('unversionedPaths')]
    public function test_returns_null_for_unversioned_paths(string $path): void
    {
        $request = new ServerRequest('GET', $path);
        $this->assertNull($this->extractor->extract($request));
    }

    public static function unversionedPaths(): array
    {
        return [
            'no version segment'    => ['/users'],
            'version not at root'   => ['/api/v1/users'],
            'version in query'      => ['/users?v=1'],
            'version-like word'     => ['/version/info'],
            'root only'             => ['/'],
        ];
    }

    public function test_custom_pattern_is_respected(): void
    {
        $extractor = new VersionExtractor('/\/api\/v(\d+)\//');
        $request   = new ServerRequest('GET', '/api/v2/users');

        $this->assertSame('v2', $extractor->extract($request));
    }

    public function test_custom_pattern_does_not_match_default_pattern(): void
    {
        $extractor = new VersionExtractor('/\/api\/v(\d+)\//');
        $request   = new ServerRequest('GET', '/v1/users');

        $this->assertNull($extractor->extract($request));
    }
}
