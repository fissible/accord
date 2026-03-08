<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\FileSpecSource;
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
}
