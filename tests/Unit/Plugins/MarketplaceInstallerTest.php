<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use SuperAICore\Plugins\InstallResult;
use SuperAICore\Plugins\MarketplaceInstaller;
use SuperAICore\Plugins\MarketplaceManifest;
use SuperAICore\Plugins\PluginInstaller;
use SuperAICore\Registry\SkillRegistry;

/**
 * End-to-end: build a tiny marketplace, install via MarketplaceInstaller,
 * point SkillRegistry at the install dir, and verify the imported skills
 * appear. Same flow we'd apply to real ruflo plugins.
 */
final class MarketplaceInstallerTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-mkt-install-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function test_imports_marketplace_subset_into_user_scope(): void
    {
        // 1. Build a 3-plugin marketplace
        $market = $this->seedMarketplace([
            ['ruflo-sparc', ['sparc-spec', 'sparc-implement']],
            ['ruflo-adr',   ['adr-create', 'adr-supersede']],
            ['ruflo-ddd',   ['ddd-bounded-context']],
        ]);

        $userHome = $this->tmp . '/userhome';
        $target = $userHome . '/.claude/plugins';

        // 2. Install only 2 of the 3
        $mi = new MarketplaceInstaller(new PluginInstaller());
        $report = $mi->importSelected(
            MarketplaceManifest::fromJsonFile($market),
            ['ruflo-sparc', 'ruflo-adr'],
            $target,
        );

        $this->assertSame(InstallResult::STATUS_INSTALLED, $report['ruflo-sparc']->status);
        $this->assertSame(InstallResult::STATUS_INSTALLED, $report['ruflo-adr']->status);
        $this->assertArrayNotHasKey('ruflo-ddd', $report);

        // 3. Verify SkillRegistry sees the skills
        $registry = new SkillRegistry(cwd: $this->tmp . '/empty-cwd', home: $userHome);
        $names = array_keys($registry->all());
        $this->assertContains('sparc-spec', $names);
        $this->assertContains('sparc-implement', $names);
        $this->assertContains('adr-create', $names);
        $this->assertContains('adr-supersede', $names);
        $this->assertNotContains('ddd-bounded-context', $names);
    }

    public function test_import_all_then_subset_unchanged(): void
    {
        $market = $this->seedMarketplace([
            ['p1', ['s1']],
            ['p2', ['s2']],
        ]);
        $target = $this->tmp . '/userhome/.claude/plugins';

        $mi = new MarketplaceInstaller(new PluginInstaller());
        $report1 = $mi->importAll(MarketplaceManifest::fromJsonFile($market), $target);
        $this->assertSame(InstallResult::STATUS_INSTALLED, $report1['p1']->status);
        $this->assertSame(InstallResult::STATUS_INSTALLED, $report1['p2']->status);

        $report2 = $mi->importAll(MarketplaceManifest::fromJsonFile($market), $target);
        $this->assertSame(InstallResult::STATUS_UNCHANGED, $report2['p1']->status);
        $this->assertSame(InstallResult::STATUS_UNCHANGED, $report2['p2']->status);
    }

    public function test_unknown_name_is_reported_per_row(): void
    {
        $market = $this->seedMarketplace([['p1', ['s1']]]);

        $mi = new MarketplaceInstaller(new PluginInstaller());
        $report = $mi->importSelected(
            MarketplaceManifest::fromJsonFile($market),
            ['p1', 'does-not-exist'],
            $this->tmp . '/dst',
        );

        $this->assertSame(InstallResult::STATUS_INSTALLED, $report['p1']->status);
        $this->assertSame(InstallResult::STATUS_FAILED, $report['does-not-exist']->status);
        $this->assertStringContainsString('not in marketplace', $report['does-not-exist']->error);
    }

    public function test_real_ruflo_marketplace_subset_installs(): void
    {
        $market = 'C:/Users/mlizp/ruflo/.claude-plugin/marketplace.json';
        if (!is_file($market)) {
            $this->markTestSkipped('ruflo marketplace not present.');
        }

        $userHome = $this->tmp . '/userhome';
        $target = $userHome . '/.claude/plugins';

        $mi = new MarketplaceInstaller(new PluginInstaller());
        $report = $mi->importSelected(
            MarketplaceManifest::fromJsonFile($market),
            ['ruflo-sparc', 'ruflo-adr', 'ruflo-ddd', 'ruflo-jujutsu'],
            $target,
        );

        foreach ($report as $name => $r) {
            $this->assertTrue($r->ok(), "{$name} should install cleanly: {$r->error}");
            $this->assertSame(InstallResult::STATUS_INSTALLED, $r->status, $name);
        }

        $registry = new SkillRegistry(cwd: $this->tmp . '/empty-cwd', home: $userHome);
        $skills = $registry->all();

        // Each ruflo plugin we picked should have at least one skill that lands.
        $this->assertNotEmpty($skills);
        $bySource = array_filter($skills, fn ($s) => $s->source === 'plugin');
        $this->assertNotEmpty($bySource, 'should see plugin-source skills after install');
    }

    /**
     * @param  array<int, array{0:string, 1:string[]}> $plugins  list of [pluginName, [skillNames...]]
     * @return string Path to the marketplace.json
     */
    private function seedMarketplace(array $plugins): string
    {
        $marketDir = $this->tmp . '/mkt';
        $pluginsDir = $marketDir . '/plugins';
        @mkdir($pluginsDir, 0755, true);

        $entries = [];
        foreach ($plugins as [$name, $skillNames]) {
            $root = $pluginsDir . '/' . $name;
            @mkdir($root . '/.claude-plugin', 0755, true);
            file_put_contents($root . '/.claude-plugin/plugin.json', json_encode([
                'name' => $name, 'version' => '0.1.0',
            ]));
            foreach ($skillNames as $skill) {
                @mkdir($root . '/skills/' . $skill, 0755, true);
                file_put_contents(
                    $root . '/skills/' . $skill . '/SKILL.md',
                    "---\nname: {$skill}\ndescription: from {$name}\n---\nbody for {$skill}\n",
                );
            }
            $entries[] = ['name' => $name, 'source' => './plugins/' . $name];
        }

        $market = $marketDir . '/marketplace.json';
        file_put_contents($market, json_encode([
            'name'    => 'test-mkt',
            'plugins' => $entries,
        ]));
        return $market;
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
