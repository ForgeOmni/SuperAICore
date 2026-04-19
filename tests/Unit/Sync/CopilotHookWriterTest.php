<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Sync\CopilotHookWriter;
use SuperAICore\Sync\Manifest;

final class CopilotHookWriterTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-hooksync-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
    }

    public function test_writes_hooks_and_preserves_other_keys(): void
    {
        file_put_contents($this->configPath(), json_encode([
            'firstLaunchAt'  => '2026-04-18T13:27:26.697Z',
            'banner'         => 'never',
            'trustedFolders' => ['/Users/xiyang/repo'],
        ]));

        $writer = $this->makeWriter();
        $hooks = [
            'PreToolUse' => [
                ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'echo pre']]],
            ],
        ];

        $result = $writer->sync($hooks);

        $this->assertSame(CopilotHookWriter::STATUS_WRITTEN, $result['status']);
        $config = json_decode(file_get_contents($this->configPath()), true);
        $this->assertSame($hooks, $config['hooks']);
        $this->assertSame('never', $config['banner']);
        $this->assertSame(['/Users/xiyang/repo'], $config['trustedFolders']);
    }

    public function test_second_sync_with_same_hooks_is_noop(): void
    {
        $writer = $this->makeWriter();
        $hooks = [
            'PostToolUse' => [
                ['matcher' => 'Write', 'hooks' => [['type' => 'command', 'command' => 'linter']]],
            ],
        ];

        $this->assertSame(CopilotHookWriter::STATUS_WRITTEN, $writer->sync($hooks)['status']);
        $this->assertSame(CopilotHookWriter::STATUS_UNCHANGED, $writer->sync($hooks)['status']);
    }

    public function test_hash_is_stable_across_key_ordering(): void
    {
        $writer = $this->makeWriter();
        $ordered = ['PreToolUse' => [['matcher' => 'A', 'hooks' => [['type' => 'command', 'command' => 'x']]]]];
        $reordered = ['PreToolUse' => [['hooks' => [['command' => 'x', 'type' => 'command']], 'matcher' => 'A']]];

        $this->assertSame(CopilotHookWriter::STATUS_WRITTEN, $writer->sync($ordered)['status']);
        $this->assertSame(CopilotHookWriter::STATUS_UNCHANGED, $writer->sync($reordered)['status']);
    }

    public function test_detects_user_edit_and_refuses_overwrite(): void
    {
        $writer = $this->makeWriter();
        $writer->sync(['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'a']]]]]);

        // Simulate user editing the hooks block by hand
        $config = json_decode(file_get_contents($this->configPath()), true);
        $config['hooks']['PreToolUse'][0]['hooks'][0]['command'] = 'user-edited';
        file_put_contents($this->configPath(), json_encode($config));

        $result = $writer->sync(['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'a']]]]]);

        $this->assertSame(CopilotHookWriter::STATUS_USER_EDITED, $result['status']);

        // And the file was NOT overwritten
        $fresh = json_decode(file_get_contents($this->configPath()), true);
        $this->assertSame('user-edited', $fresh['hooks']['PreToolUse'][0]['hooks'][0]['command']);
    }

    public function test_clear_removes_previously_written_hooks_block(): void
    {
        $writer = $this->makeWriter();
        $writer->sync(['PreToolUse' => [['matcher' => 'X', 'hooks' => [['type' => 'command', 'command' => 'c']]]]]);

        $result = $writer->sync(null);
        $this->assertSame(CopilotHookWriter::STATUS_CLEARED, $result['status']);

        $config = json_decode(file_get_contents($this->configPath()), true);
        $this->assertArrayNotHasKey('hooks', $config);
    }

    public function test_clear_on_empty_config_is_unchanged(): void
    {
        $writer = $this->makeWriter();
        $this->assertSame(CopilotHookWriter::STATUS_UNCHANGED, $writer->sync(null)['status']);
    }

    public function test_reads_hooks_from_claude_style_settings_file(): void
    {
        $settingsPath = $this->tmp . '/settings.json';
        $hooks = [
            'PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'pre']]]],
        ];
        file_put_contents($settingsPath, json_encode(['hooks' => $hooks, 'someOther' => 'ignored']));

        $this->assertSame($hooks, CopilotHookWriter::readFromSettings($settingsPath));
    }

    public function test_returns_null_when_settings_missing_or_has_no_hooks(): void
    {
        $this->assertNull(CopilotHookWriter::readFromSettings('/nonexistent.json'));

        $empty = $this->tmp . '/empty.json';
        file_put_contents($empty, json_encode(['other' => 'stuff']));
        $this->assertNull(CopilotHookWriter::readFromSettings($empty));
    }

    private function makeWriter(): CopilotHookWriter
    {
        return new CopilotHookWriter(
            $this->configPath(),
            new Manifest($this->tmp . '/.superaicore-manifest.json'),
        );
    }

    private function configPath(): string
    {
        return $this->tmp . '/config.json';
    }
}
