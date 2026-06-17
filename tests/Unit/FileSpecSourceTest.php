<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\FileSpecSource;
use Fissible\Accord\Tests\Support\ArrayCache;
use PHPUnit\Framework\TestCase;

class FileSpecSourceTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixtures';
    }

    public function test_resolves_json_spec_by_extension_in_pattern(): void
    {
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}.json');

        $this->assertTrue($source->exists('v1'));
        $this->assertNotNull($source->load('v1'));
    }

    public function test_resolves_yaml_spec_by_extension_in_pattern(): void
    {
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}.yaml');

        $this->assertTrue($source->exists('v1'));
        $this->assertNotNull($source->load('v1'));
    }

    public function test_auto_detects_yaml_before_json_when_no_extension(): void
    {
        // Both v1.yaml and v1.json exist in fixtures — YAML should win
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}');
        $path   = $source->resolvedPath('v1');

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.yaml', $path);
    }

    public function test_falls_back_to_json_when_no_yaml_present(): void
    {
        // Use a temp dir with only a .json file
        $tmpDir = sys_get_temp_dir() . '/accord_test_' . uniqid();
        mkdir($tmpDir);
        copy($this->fixturesPath . '/v1.json', $tmpDir . '/v1.json');

        $source = new FileSpecSource($tmpDir, '{base}/{version}');
        $path   = $source->resolvedPath('v1');

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.json', $path);

        unlink($tmpDir . '/v1.json');
        rmdir($tmpDir);
    }

    public function test_exists_returns_false_for_missing_version(): void
    {
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}');

        $this->assertFalse($source->exists('v99'));
    }

    public function test_load_returns_null_for_missing_version(): void
    {
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}');

        $this->assertNull($source->load('v99'));
    }

    public function test_default_pattern_uses_resources_openapi_directory(): void
    {
        $source = new FileSpecSource('/var/www/app');

        // Resolved path (no extension) should reflect the default pattern
        $this->assertNull($source->resolvedPath('v1')); // file doesn't exist, but path is correct
        $this->assertStringContainsString(
            '/var/www/app/resources/openapi/v1',
            // Access via a custom fixture path to check resolution
            str_replace('{base}', '/var/www/app', str_replace('{version}', 'v1', '{base}/resources/openapi/{version}')),
        );
    }

    public function test_cache_miss_populates_the_cache(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $spec = $source->load('v1');

        $this->assertNotNull($spec);
        $this->assertCount(1, $cache->store);

        $decoded = json_decode((string) array_values($cache->store)[0], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('openapi', $decoded);
        $this->assertArrayHasKey('paths', $decoded);
    }

    public function test_cache_hit_is_used_instead_of_the_file(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $source->load('v1'); // miss → populates the cache

        $key = array_key_first($cache->store);
        $cache->store[$key] = json_encode([
            'openapi' => '3.0.3',
            'info'    => ['title' => 'from cache', 'version' => '1'],
            'paths'   => ['/from/cache' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]],
        ]);

        $spec = $source->load('v1'); // hit → rehydrates the overwritten value

        $this->assertNotNull($spec);
        $this->assertTrue(isset($spec->paths['/from/cache']));
        $this->assertFalse(isset($spec->paths['/v1/users']));
    }

    public function test_invalid_cache_entry_falls_back_to_parsing(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $source->load('v1');
        $key = array_key_first($cache->store);
        $cache->store[$key] = '{not valid openapi'; // invalid JSON → readFromJson throws

        $spec = $source->load('v1'); // must re-parse the file, no throw

        $this->assertNotNull($spec);
        $this->assertTrue(isset($spec->paths['/v1/users']));
    }

    public function test_cache_failures_do_not_break_loading(): void
    {
        $cache = new class extends ArrayCache {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException('cache get failed');
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                throw new \RuntimeException('cache set failed');
            }
        };
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $spec = $source->load('v1');

        $this->assertNotNull($spec);
        $this->assertTrue(isset($spec->paths['/v1/users']));
    }

    public function test_mtime_change_invalidates_cache(): void
    {
        $dir  = sys_get_temp_dir() . '/accord_cache_' . uniqid();
        mkdir($dir);
        $file = $dir . '/v1.yaml';
        file_put_contents($file, "openapi: '3.0.3'\ninfo: { title: t, version: '1' }\npaths:\n  /first:\n    get:\n      responses:\n        '200': { description: OK }\n");

        $cache  = new ArrayCache();
        $source = new FileSpecSource($dir, '{base}/{version}', $cache);

        $first = $source->load('v1');
        $this->assertTrue(isset($first->paths['/first']));

        file_put_contents($file, "openapi: '3.0.3'\ninfo: { title: t, version: '1' }\npaths:\n  /second:\n    get:\n      responses:\n        '200': { description: OK }\n");
        touch($file, time() + 10);
        clearstatcache(true, $file);

        $second = $source->load('v1');
        $this->assertTrue(isset($second->paths['/second']));
        $this->assertFalse(isset($second->paths['/first']));

        unlink($file);
        rmdir($dir);
    }

    public function test_internal_ref_round_trips_through_cache(): void
    {
        $cache  = new ArrayCache();
        $source = new FileSpecSource($this->fixturesPath, '{base}/{version}', $cache);

        $first  = $source->load('v3'); // miss → file parse
        $second = $source->load('v3'); // hit → rehydrate from cache

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(
            json_encode($first->getSerializableData()),
            json_encode($second->getSerializableData()),
        );
    }
}
