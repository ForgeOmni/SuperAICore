<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Plugins\WorkspacePluginRegistry;
use SuperAICore\Tests\TestCase;

class WorkspacePluginRegistryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/sai-workspace-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rrmdir($this->tmp);
    }

    public function test_empty_workspace_returns_empty_list(): void
    {
        $registry = new WorkspacePluginRegistry($this->tmp);
        $this->assertSame([], $registry->list());
    }

    public function test_add_then_list_round_trips(): void
    {
        $registry = new WorkspacePluginRegistry($this->tmp);
        $registry->add('team-pr-review', 'github.com/org/p1', '1.0.0', WorkspacePluginRegistry::SCOPE_WORKSPACE);
        $entries = $registry->list();
        $this->assertCount(1, $entries);
        $this->assertSame('team-pr-review', $entries[0]['name']);
        $this->assertSame('1.0.0', $entries[0]['version']);
        $this->assertSame('workspace', $entries[0]['scope']);
    }

    public function test_add_replaces_existing_entry_with_same_name(): void
    {
        // Version-bump pattern: re-add the same name overwrites the
        // old entry rather than creating a duplicate.
        $registry = new WorkspacePluginRegistry($this->tmp);
        $registry->add('p1', 'src', '1.0.0');
        $registry->add('p1', 'src', '1.1.0');
        $entries = $registry->list();
        $this->assertCount(1, $entries);
        $this->assertSame('1.1.0', $entries[0]['version']);
    }

    public function test_remove_returns_true_on_existing_entry(): void
    {
        $registry = new WorkspacePluginRegistry($this->tmp);
        $registry->add('p1', 'src');
        $this->assertTrue($registry->remove('p1'));
        $this->assertSame([], $registry->list());
    }

    public function test_remove_returns_false_on_missing_entry(): void
    {
        $registry = new WorkspacePluginRegistry($this->tmp);
        $this->assertFalse($registry->remove('nope'));
    }

    public function test_pending_installs_separates_required_from_recommended(): void
    {
        $registry = new WorkspacePluginRegistry($this->tmp);
        $registry->add('required-plugin', 'src1', '1.0.0', WorkspacePluginRegistry::SCOPE_WORKSPACE);
        $registry->add('nice-to-have',    'src2', '1.0.0', WorkspacePluginRegistry::SCOPE_USER);
        $registry->add('already-here',    'src3', '1.0.0', WorkspacePluginRegistry::SCOPE_WORKSPACE);

        $pending = $registry->pendingInstalls(installedNames: ['already-here']);

        $this->assertCount(1, $pending['missing_required']);
        $this->assertSame('required-plugin', $pending['missing_required'][0]['name']);
        $this->assertCount(1, $pending['missing_recommended']);
        $this->assertSame('nice-to-have', $pending['missing_recommended'][0]['name']);
    }

    public function test_unknown_scope_falls_back_to_user(): void
    {
        // Defensive normalise — a typo'd manifest shouldn't auto-install
        // anything as workspace-required by accident.
        $registry = new WorkspacePluginRegistry($this->tmp);
        $registry->add('p1', 'src', null, 'WeIrD-VaLuE');
        $entries = $registry->list();
        $this->assertSame('user', $entries[0]['scope']);
    }

    public function test_corrupt_manifest_returns_empty_list(): void
    {
        $manifest = $this->tmp . '/' . WorkspacePluginRegistry::MANIFEST_PATH;
        mkdir(dirname($manifest), 0755, true);
        file_put_contents($manifest, 'not-json{');
        $registry = new WorkspacePluginRegistry($this->tmp);
        $this->assertSame([], $registry->list());
    }

    private function rrmdir(string $path): void
    {
        if (! is_dir($path)) return;
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $path . '/' . $entry;
            if (is_dir($full)) $this->rrmdir($full);
            else unlink($full);
        }
        rmdir($path);
    }
}
