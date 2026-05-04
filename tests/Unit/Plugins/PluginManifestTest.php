<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use SuperAICore\Plugins\MarketplaceManifest;
use SuperAICore\Plugins\PluginManifest;

/**
 * Mirror of SuperAgent's PluginManifestTest — verifies SuperAICore
 * reads the same wire format from the same on-disk files.
 */
final class PluginManifestTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-pluginmanifest-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function test_legacy_superagent_shape_loads(): void
    {
        $f = $this->tmp . '/plugin.json';
        file_put_contents($f, json_encode([
            'name'               => 'my-plugin',
            'version'            => '1.2.3',
            'enabled_by_default' => true,
            'skills_dir'         => 'capabilities',
        ]));

        $m = PluginManifest::fromJsonFile($f);
        $this->assertSame('my-plugin', $m->name);
        $this->assertTrue($m->enabledByDefault);
        $this->assertSame('capabilities', $m->skillsDir);
        $this->assertSame([], $m->keywords);
    }

    public function test_ruflo_shape_loads(): void
    {
        $f = $this->tmp . '/plugin.json';
        file_put_contents($f, json_encode([
            'name'    => 'ruflo-sparc',
            'version' => '0.1.0',
            'author'  => ['name' => 'ruvnet', 'url' => 'https://github.com/ruvnet'],
            'license' => 'MIT',
            'keywords' => ['sparc', 'methodology'],
        ]));

        $m = PluginManifest::fromJsonFile($f);
        $this->assertSame('ruvnet', $m->author);
        $this->assertSame('https://github.com/ruvnet', $m->authorUrl);
        $this->assertSame('MIT', $m->license);
        $this->assertSame(['sparc', 'methodology'], $m->keywords);
    }

    public function test_required_fields_throw(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginManifest::fromArray(['name' => 'x']);
    }

    public function test_discovery_prefers_claude_plugin_subdir(): void
    {
        $root = $this->tmp . '/p';
        @mkdir($root . '/.claude-plugin', 0755, true);
        file_put_contents($root . '/.claude-plugin/plugin.json', json_encode(['name' => 'a', 'version' => '1']));
        file_put_contents($root . '/plugin.json', json_encode(['name' => 'b', 'version' => '2']));

        $found = PluginManifest::discoverManifestPath($root);
        $this->assertNotNull($found);
        $this->assertSame('a', PluginManifest::fromJsonFile($found)->name);
    }

    public function test_real_ruflo_marketplace_loads_with_correct_root(): void
    {
        $market = 'C:/Users/mlizp/ruflo/.claude-plugin/marketplace.json';
        if (!is_file($market)) {
            $this->markTestSkipped('ruflo marketplace not present.');
        }

        $mm = MarketplaceManifest::fromJsonFile($market);
        $this->assertSame('ruflo', $mm->name);
        $this->assertGreaterThan(20, count($mm->plugins));

        // Spot-check that the .claude-plugin parent walk worked.
        $sparc = null;
        foreach ($mm->plugins as $entry) {
            if ($entry->name === 'ruflo-sparc') $sparc = $entry;
        }
        $this->assertNotNull($sparc);
        $this->assertDirectoryExists($sparc->resolvedPath());
        $this->assertNotNull(PluginManifest::discoverManifestPath($sparc->resolvedPath()));
    }

    private function rrm(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($p) ? $this->rrm($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
