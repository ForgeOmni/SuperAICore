<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CliInstaller;

final class CliInstallerTest extends TestCase
{
    public function test_sources_covers_all_declared_backends(): void
    {
        $matrix = CliInstaller::sources();

        foreach (CliInstaller::INSTALLABLE_BACKENDS as $b) {
            $this->assertArrayHasKey($b, $matrix, "missing source entry for {$b}");
            $this->assertNotEmpty($matrix[$b], "empty source list for {$b}");
            $this->assertSame(CliInstaller::SOURCE_NPM, $matrix[$b][0]['source'], "npm should be the default source for {$b}");
        }
    }

    public function test_superagent_is_not_installable(): void
    {
        // superagent is a PHP SDK, not a CLI — explicit follow-ups doc decision
        $this->assertNotContains('superagent', CliInstaller::INSTALLABLE_BACKENDS);
        $this->assertNull(CliInstaller::resolveSource('superagent'));
    }

    public function test_resolve_source_picks_default_when_null(): void
    {
        $opt = CliInstaller::resolveSource('claude');
        $this->assertSame(CliInstaller::SOURCE_NPM, $opt['source']);
        $this->assertContains('@anthropic-ai/claude-code', $opt['argv']);
    }

    public function test_resolve_source_picks_named_source(): void
    {
        $opt = CliInstaller::resolveSource('codex', CliInstaller::SOURCE_BREW);
        $this->assertSame(CliInstaller::SOURCE_BREW, $opt['source']);
        $this->assertSame(['brew', 'install', 'codex'], $opt['argv']);
    }

    public function test_resolve_source_returns_null_for_unknown_backend(): void
    {
        $this->assertNull(CliInstaller::resolveSource('mystery-engine'));
    }

    public function test_resolve_source_returns_null_when_named_source_absent(): void
    {
        // copilot only has npm — asking for brew should report nothing
        $this->assertNull(CliInstaller::resolveSource('copilot', CliInstaller::SOURCE_BREW));
    }

    public function test_install_hint_is_human_readable_shell_command(): void
    {
        $hint = CliInstaller::installHint('gemini');
        $this->assertStringContainsString('npm', $hint);
        $this->assertStringContainsString('@google/gemini-cli', $hint);
    }

    public function test_dry_run_prints_command_without_executing(): void
    {
        $buffer = '';
        $exit = CliInstaller::install(
            'claude',
            null,
            function (string $c) use (&$buffer) { $buffer .= $c; },
            dryRun: true,
        );

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[dry-run]', $buffer);
        $this->assertStringContainsString('@anthropic-ai/claude-code', $buffer);
    }

    public function test_install_rejects_unknown_backend_before_spawning(): void
    {
        $buffer = '';
        $exit = CliInstaller::install(
            'nope',
            null,
            function (string $c) use (&$buffer) { $buffer .= $c; },
        );

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unknown backend', $buffer);
    }

    public function test_is_tool_available_returns_false_for_invalid_source(): void
    {
        $this->assertFalse(CliInstaller::isToolAvailable('nonexistent-tool-xyz'));
    }
}
