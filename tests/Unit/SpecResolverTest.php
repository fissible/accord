<?php

declare(strict_types=1);

namespace Fissible\Accord\Tests\Unit;

use Fissible\Accord\SpecResolver;
use PHPUnit\Framework\TestCase;

class SpecResolverTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixtures';
    }

    public function test_resolves_path_using_default_pattern(): void
    {
        $resolver = new SpecResolver('/var/www/app');

        $this->assertSame(
            '/var/www/app/resources/contract/v1.json',
            $resolver->resolve('v1'),
        );
    }

    public function test_resolves_path_using_custom_pattern(): void
    {
        $resolver = new SpecResolver('/base', '{base}/specs/{version}.yaml');

        $this->assertSame('/base/specs/v2.yaml', $resolver->resolve('v2'));
    }

    public function test_exists_returns_true_for_present_fixture(): void
    {
        $resolver = new SpecResolver($this->fixturesPath, '{base}/{version}.json');

        $this->assertTrue($resolver->exists('v1'));
    }

    public function test_exists_returns_false_for_missing_spec(): void
    {
        $resolver = new SpecResolver($this->fixturesPath, '{base}/{version}.json');

        $this->assertFalse($resolver->exists('v99'));
    }
}
