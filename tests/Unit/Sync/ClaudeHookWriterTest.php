<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Sync\ClaudeHookWriter;
use SuperAICore\Sync\Manifest;

final class ClaudeHookWriterTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-claudehook-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
    }

    public function test_writes_hooks_and_preserves_other_settings_keys(): void
    {
        file_put_contents($this->settingsPath(), json_encode([
            'model'       => 'claude-opus-4-7',
            'permissions' => ['allow' => ['Bash(npm:*)']],
            'mcpServers'  => ['some-server' => ['command' => 'npx']],
        ]));

        $writer = $this->makeWriter();
        $hooks = [
            'PreToolUse' => [
                ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'echo pre']]],
            ],
        ];

        $result = $writer->sync($hooks);

        $this->assertSame(ClaudeHookWriter::STATUS_WRITTEN, $result['status']);
        $settings = json_decode(file_get_contents($this->settingsPath()), true);
        $this->assertSame($hooks, $settings['hooks']);
        $this->assertSame('claude-opus-4-7', $settings['model']);
        $this->assertSame(['some-server' => ['command' => 'npx']], $settings['mcpServers']);
    }

    public function test_engine_key_is_claude(): void
    {
        $this->assertSame('claude', $this->makeWriter()->engineKey());
    }

    public function test_is_available_creates_parent_dir(): void
    {
        $writer = new ClaudeHookWriter(
            $this->tmp . '/.claude/settings.json',
            new Manifest($this->tmp . '/.claude/.superaicore-manifest.json'),
        );
        $this->assertTrue($writer->isAvailable());
        $this->assertDirectoryExists($this->tmp . '/.claude');
    }

    public function test_idempotent_second_sync(): void
    {
        $writer = $this->makeWriter();
        $hooks = ['Stop' => [['hooks' => [['type' => 'command', 'command' => 'finish']]]]];
        $this->assertSame(ClaudeHookWriter::STATUS_WRITTEN, $writer->sync($hooks)['status']);
        $this->assertSame(ClaudeHookWriter::STATUS_UNCHANGED, $writer->sync($hooks)['status']);
    }

    public function test_detects_user_edit(): void
    {
        $writer = $this->makeWriter();
        $writer->sync(['PreToolUse' => [['matcher' => 'B', 'hooks' => [['type' => 'command', 'command' => 'a']]]]]);

        $settings = json_decode(file_get_contents($this->settingsPath()), true);
        $settings['hooks']['PreToolUse'][0]['hooks'][0]['command'] = 'user-edited';
        file_put_contents($this->settingsPath(), json_encode($settings));

        $result = $writer->sync(['PreToolUse' => [['matcher' => 'B', 'hooks' => [['type' => 'command', 'command' => 'a']]]]]);
        $this->assertSame(ClaudeHookWriter::STATUS_USER_EDITED, $result['status']);

        $fresh = json_decode(file_get_contents($this->settingsPath()), true);
        $this->assertSame('user-edited', $fresh['hooks']['PreToolUse'][0]['hooks'][0]['command']);
    }

    public function test_clear_only_removes_hooks_key(): void
    {
        file_put_contents($this->settingsPath(), json_encode(['model' => 'x']));
        $writer = $this->makeWriter();
        $writer->sync(['PreToolUse' => [['matcher' => 'X', 'hooks' => [['type' => 'command', 'command' => 'c']]]]]);

        $this->assertSame(ClaudeHookWriter::STATUS_CLEARED, $writer->sync(null)['status']);

        $settings = json_decode(file_get_contents($this->settingsPath()), true);
        $this->assertArrayNotHasKey('hooks', $settings);
        $this->assertSame('x', $settings['model']);
    }

    private function makeWriter(): ClaudeHookWriter
    {
        return new ClaudeHookWriter(
            $this->settingsPath(),
            new Manifest($this->tmp . '/.superaicore-manifest.json'),
        );
    }

    private function settingsPath(): string
    {
        return $this->tmp . '/settings.json';
    }
}
