<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Support\KimiRuntime;

/**
 * Pins the layout-probe precedence that every Kimi support surface
 * (auth detection, MCP sync, skills bridge) relies on:
 * $KIMI_CODE_HOME > ~/.kimi-code > ~/.kimi > default-to-kimi-code.
 */
final class KimiRuntimeTest extends TestCase
{
    public function test_legacy_layout_detected_when_only_dot_kimi_exists(): void
    {
        $this->withSandboxHome(['.kimi'], function () {
            $this->assertFalse(KimiRuntime::isKimiCode());
            $this->assertSame('.kimi/mcp.json', KimiRuntime::mcpConfigRelPath());
            $this->assertStringEndsWith('/.kimi/mcp.json', KimiRuntime::mcpConfigPath());
        });
    }

    public function test_kimi_code_layout_detected_and_wins_over_legacy(): void
    {
        $this->withSandboxHome(['.kimi', '.kimi-code'], function () {
            $this->assertTrue(KimiRuntime::isKimiCode());
            $this->assertSame('.kimi-code/mcp.json', KimiRuntime::mcpConfigRelPath());
            $this->assertSame('.kimi-code/skills', KimiRuntime::skillsRelPath());
        });
    }

    public function test_fresh_machine_defaults_to_kimi_code(): void
    {
        $this->withSandboxHome([], function () {
            $this->assertTrue(KimiRuntime::isKimiCode());
            $this->assertSame('.kimi-code/mcp.json', KimiRuntime::mcpConfigRelPath());
        });
    }

    public function test_kimi_code_home_env_overrides_layout(): void
    {
        $this->withSandboxHome(['.kimi'], function (string $sandbox) {
            putenv('KIMI_CODE_HOME=' . $sandbox . '/custom-kimi');
            try {
                $this->assertTrue(KimiRuntime::isKimiCode());
                $this->assertSame($sandbox . '/custom-kimi', KimiRuntime::codeHome());
                $this->assertSame($sandbox . '/custom-kimi/mcp.json', KimiRuntime::mcpConfigPath());
                // Under $HOME → the relative contract tracks the env dir.
                $this->assertSame('custom-kimi/mcp.json', KimiRuntime::mcpConfigRelPath());
                $this->assertSame(
                    $sandbox . '/custom-kimi/credentials/kimi-code.json',
                    KimiRuntime::credentialCandidates()[0],
                );
            } finally {
                putenv('KIMI_CODE_HOME');
            }
        });
    }

    public function test_kimi_code_home_outside_home_falls_back_to_default_rel_path(): void
    {
        $this->withSandboxHome([], function () {
            $outside = sys_get_temp_dir() . '/kimi-elsewhere-' . bin2hex(random_bytes(3));
            putenv('KIMI_CODE_HOME=' . $outside);
            try {
                // Absolute path honours the env override…
                $this->assertSame($outside . '/mcp.json', KimiRuntime::mcpConfigPath());
                // …but the $HOME-relative contract can't express it, so the
                // stock location is used for relative consumers.
                $this->assertSame('.kimi-code/mcp.json', KimiRuntime::mcpConfigRelPath());
            } finally {
                putenv('KIMI_CODE_HOME');
            }
        });
    }

    public function test_credential_candidates_cover_both_generations(): void
    {
        $this->withSandboxHome([], function (string $sandbox) {
            $this->assertSame([
                $sandbox . '/.kimi-code/credentials/kimi-code.json',
                $sandbox . '/.kimi/credentials/kimi-code.json',
            ], KimiRuntime::credentialCandidates());
        });
    }

    /**
     * Run $fn with HOME pointed at a throwaway dir containing the given
     * subdirs; $fn receives the sandbox path. Restores HOME afterwards.
     *
     * @param string[] $dirs
     */
    private function withSandboxHome(array $dirs, callable $fn): void
    {
        $sandbox = sys_get_temp_dir() . '/kimi-rt-' . bin2hex(random_bytes(3));
        mkdir($sandbox, 0755, true);
        foreach ($dirs as $d) {
            mkdir($sandbox . '/' . $d, 0755, true);
        }
        $prev = getenv('HOME');
        putenv('HOME=' . $sandbox);
        try {
            $fn($sandbox);
        } finally {
            putenv($prev === false ? 'HOME' : 'HOME=' . $prev);
            foreach ($dirs as $d) {
                @rmdir($sandbox . '/' . $d);
            }
            @rmdir($sandbox);
        }
    }
}
