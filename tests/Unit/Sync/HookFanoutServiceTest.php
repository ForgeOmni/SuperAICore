<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Sync\ClaudeHookWriter;
use SuperAICore\Sync\CopilotHookWriter;
use SuperAICore\Sync\HookFanoutService;
use SuperAICore\Sync\HookWriterInterface;
use SuperAICore\Sync\Manifest;

final class HookFanoutServiceTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-fanout-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
        @mkdir($this->tmp . '/claude', 0755, true);
        @mkdir($this->tmp . '/copilot', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function test_fans_to_every_registered_writer(): void
    {
        $fanout = $this->buildFanout();

        $hooks = ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'x']]]]];
        $report = $fanout->sync($hooks);

        $this->assertSame(['claude', 'copilot'], array_keys($report));
        $this->assertSame('written', $report['claude']['status']);
        $this->assertSame('written', $report['copilot']['status']);
    }

    public function test_only_filter_limits_engines(): void
    {
        $fanout = $this->buildFanout();
        $hooks = ['Stop' => [['hooks' => [['type' => 'command', 'command' => 'end']]]]];

        $report = $fanout->sync($hooks, only: ['copilot']);

        $this->assertSame(['copilot'], array_keys($report));
        $this->assertSame('written', $report['copilot']['status']);
    }

    public function test_unknown_engine_in_filter_reports_unknown(): void
    {
        $fanout = $this->buildFanout();
        $report = $fanout->sync(['Stop' => [['hooks' => []]]], only: ['kiro']);

        $this->assertArrayHasKey('kiro', $report);
        $this->assertSame('unknown', $report['kiro']['status']);
        $this->assertNull($report['kiro']['path']);
    }

    public function test_unavailable_writer_is_skipped_gracefully(): void
    {
        $fanout = new HookFanoutService();
        $fanout->register(new class implements HookWriterInterface {
            public function engineKey(): string { return 'ghost'; }
            public function isAvailable(): bool { return false; }
            public function sync(?array $h): array { throw new \RuntimeException('should not be called'); }
        });

        $report = $fanout->sync(['Stop' => [['hooks' => []]]]);

        $this->assertSame('unavailable', $report['ghost']['status']);
    }

    public function test_resolve_source_prefers_explicit_path(): void
    {
        $explicit = $this->tmp . '/explicit.json';
        file_put_contents($explicit, json_encode([
            'PreToolUse' => [['matcher' => 'X', 'hooks' => []]],
        ]));

        [$path, $hooks] = HookFanoutService::resolveSource($explicit, $this->tmp);

        $this->assertSame($explicit, $path);
        $this->assertSame(['PreToolUse'], array_keys($hooks));
    }

    public function test_resolve_source_falls_back_to_superaicore_then_claude(): void
    {
        // No .superaicore/hooks.json — should fall back to .claude/settings.json
        @mkdir($this->tmp . '/.claude', 0755, true);
        file_put_contents($this->tmp . '/.claude/settings.json', json_encode([
            'hooks' => ['Stop' => [['hooks' => []]]],
            'model' => 'opus',
        ]));

        [$path, $hooks] = HookFanoutService::resolveSource(null, $this->tmp);

        $this->assertStringEndsWith('settings.json', $path);
        $this->assertSame(['Stop'], array_keys($hooks));

        // Now seed .superaicore/hooks.json (bare-map shape) — should win
        @mkdir($this->tmp . '/.superaicore', 0755, true);
        file_put_contents($this->tmp . '/.superaicore/hooks.json', json_encode([
            'PreToolUse' => [['matcher' => 'A', 'hooks' => []]],
        ]));

        [$path2, $hooks2] = HookFanoutService::resolveSource(null, $this->tmp);
        $this->assertStringEndsWith('hooks.json', $path2);
        $this->assertSame(['PreToolUse'], array_keys($hooks2));
    }

    public function test_resolve_source_returns_null_when_nothing_present(): void
    {
        [$path, $hooks] = HookFanoutService::resolveSource(null, $this->tmp);
        $this->assertNull($path);
        $this->assertNull($hooks);
    }

    public function test_resolve_source_rejects_non_hooks_payload(): void
    {
        // Only top-level keys are random — not a bare hooks map, no `hooks` key
        $f = $this->tmp . '/junk.json';
        file_put_contents($f, json_encode(['random' => 'thing', 'other' => true]));

        [$path, $hooks] = HookFanoutService::resolveSource($f, $this->tmp);
        $this->assertNull($path);
        $this->assertNull($hooks);
    }

    private function buildFanout(): HookFanoutService
    {
        $fanout = new HookFanoutService();
        $fanout->register(new ClaudeHookWriter(
            $this->tmp . '/claude/settings.json',
            new Manifest($this->tmp . '/claude/.superaicore-manifest.json'),
        ));
        $fanout->register(new CopilotHookWriter(
            $this->tmp . '/copilot/config.json',
            new Manifest($this->tmp . '/copilot/.superaicore-manifest.json'),
        ));
        return $fanout;
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
