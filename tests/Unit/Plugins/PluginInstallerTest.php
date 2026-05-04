<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use SuperAICore\Plugins\InstallResult;
use SuperAICore\Plugins\PluginInstaller;

final class PluginInstallerTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-plugin-install-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function test_installs_a_plugin_and_marks_subsequent_run_unchanged(): void
    {
        $src = $this->seedPlugin('demo', '0.1.0');
        $target = $this->tmp . '/installed';

        $installer = new PluginInstaller();
        $r1 = $installer->install($src, $target);
        $this->assertSame(InstallResult::STATUS_INSTALLED, $r1->status, $r1->error ?? '');
        $this->assertFileExists($target . '/demo/.claude-plugin/plugin.json');
        $this->assertFileExists($target . '/demo/skills/demo-skill/SKILL.md');
        $this->assertFileExists($target . '/demo/.superaicore-install.json');

        $r2 = $installer->install($src, $target);
        $this->assertSame(InstallResult::STATUS_UNCHANGED, $r2->status);
    }

    public function test_updates_when_source_changes(): void
    {
        $src = $this->seedPlugin('demo', '0.1.0');
        $target = $this->tmp . '/installed';
        $installer = new PluginInstaller();
        $installer->install($src, $target);

        // Bump the source skill body
        file_put_contents($src . '/skills/demo-skill/SKILL.md',
            "---\nname: demo-skill\n---\nv2 body\n");

        $r = $installer->install($src, $target);
        $this->assertSame(InstallResult::STATUS_UPDATED, $r->status);
        $this->assertStringContainsString('v2 body', file_get_contents($target . '/demo/skills/demo-skill/SKILL.md'));
    }

    public function test_refuses_overwrite_when_user_edited(): void
    {
        $src = $this->seedPlugin('demo', '0.1.0');
        $target = $this->tmp . '/installed';
        $installer = new PluginInstaller();
        $installer->install($src, $target);

        // User edits an installed file
        file_put_contents($target . '/demo/skills/demo-skill/SKILL.md', 'human-touched');

        // Now bump the source so the installer would otherwise update
        file_put_contents($src . '/skills/demo-skill/SKILL.md',
            "---\nname: demo-skill\n---\nupstream-v2\n");

        $r = $installer->install($src, $target);
        $this->assertSame(InstallResult::STATUS_USER_EDITED, $r->status);
        $this->assertStringContainsString('human-touched', file_get_contents($target . '/demo/skills/demo-skill/SKILL.md'));
    }

    public function test_force_overrides_user_edit(): void
    {
        $src = $this->seedPlugin('demo', '0.1.0');
        $target = $this->tmp . '/installed';
        $installer = new PluginInstaller();
        $installer->install($src, $target);
        file_put_contents($target . '/demo/skills/demo-skill/SKILL.md', 'human-touched');
        file_put_contents($src . '/skills/demo-skill/SKILL.md',
            "---\nname: demo-skill\n---\nupstream-v2\n");

        $r = $installer->install($src, $target, force: true);
        $this->assertSame(InstallResult::STATUS_UPDATED, $r->status);
        $this->assertStringContainsString('upstream-v2', file_get_contents($target . '/demo/skills/demo-skill/SKILL.md'));
    }

    public function test_uninstall_removes_only_installer_owned_files(): void
    {
        $src = $this->seedPlugin('demo', '0.1.0');
        $target = $this->tmp . '/installed';
        $installer = new PluginInstaller();
        $installer->install($src, $target);

        // User drops their own file inside the plugin dir
        @mkdir($target . '/demo/notes', 0755, true);
        file_put_contents($target . '/demo/notes/keep-me.md', 'my own data');

        $r = $installer->uninstall('demo', $target);
        $this->assertSame(InstallResult::STATUS_REMOVED, $r->status);

        // Plugin metadata is gone
        $this->assertFileDoesNotExist($target . '/demo/.claude-plugin/plugin.json');
        $this->assertFileDoesNotExist($target . '/demo/skills/demo-skill/SKILL.md');
        // User's own file survives
        $this->assertFileExists($target . '/demo/notes/keep-me.md');
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $src = $this->seedPlugin('demo', '0.1.0');
        $target = $this->tmp . '/installed';
        $installer = new PluginInstaller();

        $r = $installer->install($src, $target, dryRun: true);
        $this->assertSame(InstallResult::STATUS_INSTALLED, $r->status);
        $this->assertGreaterThan(0, $r->filesCopied);
        $this->assertDirectoryDoesNotExist($target . '/demo');
    }

    public function test_failure_when_source_lacks_manifest(): void
    {
        $bare = $this->tmp . '/bare';
        @mkdir($bare . '/skills', 0755, true);
        file_put_contents($bare . '/skills/x.md', 'no manifest');

        $r = (new PluginInstaller())->install($bare, $this->tmp . '/dst');
        $this->assertSame(InstallResult::STATUS_FAILED, $r->status);
        $this->assertStringContainsString('no plugin.json', $r->error);
    }

    /**
     * Seed a minimal plugin: manifest + 1 skill + 1 agent + 1 command.
     * Returns the plugin root.
     */
    private function seedPlugin(string $name, string $version): string
    {
        $root = $this->tmp . '/src/' . $name;
        @mkdir($root . '/.claude-plugin', 0755, true);
        @mkdir($root . '/skills/demo-skill', 0755, true);
        @mkdir($root . '/agents', 0755, true);
        @mkdir($root . '/commands', 0755, true);

        file_put_contents($root . '/.claude-plugin/plugin.json', json_encode([
            'name'    => $name,
            'version' => $version,
        ]));
        file_put_contents($root . '/skills/demo-skill/SKILL.md',
            "---\nname: demo-skill\ndescription: example\n---\nv1 body\n");
        file_put_contents($root . '/agents/demo-agent.md',
            "---\nname: demo-agent\n---\nagent body\n");
        file_put_contents($root . '/commands/demo-command.md',
            "---\nname: demo-command\n---\ncommand body\n");
        file_put_contents($root . '/README.md', "# {$name}\n");

        return $root;
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
