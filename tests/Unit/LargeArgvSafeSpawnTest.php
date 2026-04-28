<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\Concerns\LargeArgvSafeSpawn;
use Symfony\Component\Process\Process;

/**
 * Covers the Windows large-argv workaround used by Kimi/Kiro/Copilot —
 * the CLIs that take the prompt as an argv argument (not stdin) and
 * therefore can't use the simpler `setInput()` fix the stdin-reading
 * engines (Claude/Codex/Gemini) got.
 *
 * The trait must:
 *   1. Pass-through unchanged on macOS/Linux (their argv limits sit
 *      well above our typical 25K agent-task prompts).
 *   2. Pass-through on Windows for short argv (no need to pay the
 *      PowerShell wrapper cost for trivial calls).
 *   3. Route long argv on Windows through a generated .ps1 wrapper
 *      that uses PowerShell's splat operator (`& $bin @args`),
 *      bypassing cmd.exe's 8K command-line cap.
 */
final class LargeArgvSafeSpawnTest extends TestCase
{
    public function test_short_argv_on_any_platform_yields_plain_process(): void
    {
        $sub = new TestableLargeArgvSpawner();
        $proc = $sub->buildLargeArgvSafeProcessPublic(['echo', 'hello']);

        // Expect a plain Process invoking the binary directly — not
        // a powershell.exe wrapper.
        $cmdline = $proc->getCommandLine();
        $this->assertStringNotContainsStringIgnoringCase('powershell', $cmdline);
    }

    public function test_long_argv_on_windows_routes_via_powershell_wrapper(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only argv-limit workaround');
        }

        $sub = new TestableLargeArgvSpawner();
        // 10K of payload — well above the 6500-char threshold + cmd.exe's
        // 8K cap, well below the kernel-level 32K argv limit PowerShell
        // hits when calling CreateProcess directly.
        $bigPrompt = str_repeat("AB\n", 4000);
        $proc = $sub->buildLargeArgvSafeProcessPublic(['kimi', '--prompt', $bigPrompt]);

        $cmdline = $proc->getCommandLine();
        $this->assertStringContainsStringIgnoringCase('powershell', $cmdline);
        $this->assertStringContainsString('-File', $cmdline);
    }

    public function test_long_argv_on_posix_still_uses_plain_process(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX-only path');
        }

        $sub = new TestableLargeArgvSpawner();
        $bigPrompt = str_repeat('x', 30000);
        $proc = $sub->buildLargeArgvSafeProcessPublic(['echo', $bigPrompt]);

        // On Linux/macOS, argv limits are 128K+ (getconf ARG_MAX), so
        // we don't need the PowerShell workaround. Stay with a plain
        // Process invocation.
        $cmdline = $proc->getCommandLine();
        $this->assertStringNotContainsStringIgnoringCase('powershell', $cmdline);
    }

    public function test_generated_ps1_escapes_single_quotes_correctly(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only ps1 generation path');
        }

        $sub = new TestableLargeArgvSpawner();
        $bigPrompt = str_repeat('x', 7000) . "it's a 'test' with quotes";
        $proc = $sub->buildLargeArgvSafeProcessPublic(['kimi', '--prompt', $bigPrompt]);

        // Extract the .ps1 path from the wrapper command line and read
        // back the script body — single quotes in the original argv
        // must be doubled (PowerShell single-quote escape).
        $cmdline = $proc->getCommandLine();
        $this->assertMatchesRegularExpression('/saicore-argv-[a-f0-9]+\.ps1/', $cmdline);
        if (preg_match('#([A-Z]:\\\\[^"\']+\.ps1)#i', $cmdline, $m)) {
            $body = file_get_contents($m[1]);
            $this->assertStringContainsString("it''s a ''test'' with quotes", $body);
            // Also verify the splat operator + array literal pattern.
            $this->assertStringContainsString('@arguments', $body);
            @unlink($m[1]);
        }
    }
}

final class TestableLargeArgvSpawner
{
    use LargeArgvSafeSpawn;

    public function buildLargeArgvSafeProcessPublic(array $args, ?string $cwd = null, array $env = []): Process
    {
        return $this->buildLargeArgvSafeProcess($args, $cwd, $env);
    }
}
