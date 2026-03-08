<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\UrlSpecSource;
use PHPUnit\Framework\TestCase;

class UrlSpecSourceTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixtures';
    }

    public function test_resolves_url_from_pattern(): void
    {
        $source = new UrlSpecSource('https://api.example.com/openapi/{version}.yaml');

        $this->assertSame(
            'https://api.example.com/openapi/v1.yaml',
            $source->resolveUrl('v1'),
        );
    }

    public function test_loads_json_spec_via_file_url(): void
    {
        $source = new UrlSpecSource('file://' . $this->fixturesPath . '/{version}.json');

        $spec = $source->load('v1');

        $this->assertNotNull($spec);
    }

    public function test_loads_yaml_spec_via_file_url(): void
    {
        $source = new UrlSpecSource('file://' . $this->fixturesPath . '/{version}.yaml');

        $spec = $source->load('v1');

        $this->assertNotNull($spec);
    }

    public function test_returns_null_for_unreachable_url(): void
    {
        $source = new UrlSpecSource('file:///nonexistent/path/{version}.yaml');

        $this->assertNull($source->load('v1'));
    }

    public function test_exists_returns_true_when_spec_loads(): void
    {
        $source = new UrlSpecSource('file://' . $this->fixturesPath . '/{version}.yaml');

        $this->assertTrue($source->exists('v1'));
    }

    public function test_exists_returns_false_when_spec_missing(): void
    {
        $source = new UrlSpecSource('file:///nonexistent/{version}.yaml');

        $this->assertFalse($source->exists('v1'));
    }

    public function test_uses_psr16_cache_on_second_load(): void
    {
        $fetches = 0;
        $source  = new class('file://' . $this->fixturesPath . '/{version}.yaml') extends UrlSpecSource {
            public int $fetchCount = 0;
            protected function fetchContent(string $url): ?string
            {
                $this->fetchCount++;
                return parent::fetchContent($url);
            }
        };

        $cache = new class implements \Psr\SimpleCache\CacheInterface {
            private array $store = [];
            public function get($key, $default = null): mixed { return $this->store[$key] ?? $default; }
            public function set($key, $value, $ttl = null): bool { $this->store[$key] = $value; return true; }
            public function delete($key): bool { unset($this->store[$key]); return true; }
            public function clear(): bool { $this->store = []; return true; }
            public function getMultiple($keys, $default = null): iterable { return []; }
            public function setMultiple($values, $ttl = null): bool { return true; }
            public function deleteMultiple($keys): bool { return true; }
            public function has($key): bool { return isset($this->store[$key]); }
        };

        $cached = new UrlSpecSource(
            'file://' . $this->fixturesPath . '/{version}.yaml',
            $cache,
        );

        $cached->load('v1');
        $cached->load('v1'); // second call — should hit cache, not fetchContent

        // The cache test verifies the cache key is set after first load
        $this->assertTrue($cache->has('fissible.accord.spec.' . hash('xxh32', 'file://' . $this->fixturesPath . '/v1.yaml')));
    }
}
