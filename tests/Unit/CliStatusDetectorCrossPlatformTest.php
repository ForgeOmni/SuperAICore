<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CliStatusDetector;

/**
 * Covers the cross-platform contract of CliStatusDetector — specifically
 * the bits that previously assumed POSIX shell semantics and silently
 * broke on Windows (issue #175 / SuperTeam #N): cmd.exe misparses
 * `2>/dev/null` as an output filename, aborting the whole command and
 * leaving `auth=null` even when the underlying CLI is logged in.
 *
 * We don't spawn real binaries here — every assertion drives `static::`
 * methods via a probe-overriding subclass so the test suite stays fast
 * and deterministic on all three platforms.
 */
final class CliStatusDetectorCrossPlatformTest extends TestCase
{
    protected function setUp(): void
    {
        ProbeRecordingDetector::$probeReturns = [];
        ProbeRecordingDetector::$probeMergeFlags = [];
        ProbeRecordingDetector::$probeCommands = [];
    }

    public function test_safe_probe_command_does_not_contain_unix_redirect(): void
    {
        // Drive a single status detection and inspect the recorded commands.
        // None of the probes we run should contain `2>/dev/null` or `2>NUL`
        // — Symfony Process already separates the streams for us, so shell
        // redirects are pure platform-incompat surface area.
        ProbeRecordingDetector::$probeReturns = [
            // --version probe
            '"/fake/claude" --version' => '2.1.121',
            // auth status probe — return a JSON-ish blob so detectAuth() is happy
            '"/fake/claude" auth status' => '{"loggedIn":true,"authMethod":"claude.ai"}',
        ];

        $auth = ProbeRecordingDetector::callDetectAuth('claude', '/fake/claude');

        $this->assertSame(['loggedIn' => true, 'authMethod' => 'claude.ai'], $auth);
        foreach (ProbeRecordingDetector::$probeCommands as $cmd) {
            $this->assertStringNotContainsString('2>/dev/null', $cmd, "command leaked Unix redirect: {$cmd}");
            $this->assertStringNotContainsString('2>NUL', $cmd, "command leaked Windows redirect: {$cmd}");
        }
    }

    public function test_codex_login_status_probe_opts_into_merge_stderr(): void
    {
        // Codex prints "Logged in …" to stderr in some 0.5+ builds — host
        // must call safeProbeOutput with mergeStderr:true so the stderr
        // line surfaces in $out for the str_contains check.
        ProbeRecordingDetector::$probeReturns = [
            '"/fake/codex" --version' => 'codex 0.5.4',
            '"/fake/codex" login status' => 'Logged in via ChatGPT',
        ];

        $auth = ProbeRecordingDetector::callDetectAuth('codex', '/fake/codex');

        $this->assertTrue($auth['loggedIn']);
        $this->assertSame('ChatGPT', $auth['method']);
        // The codex login-status call must have asked to merge stderr.
        $loginStatusCalls = array_keys(array_filter(
            ProbeRecordingDetector::$probeMergeFlags,
            fn (bool $merge, string $cmd) => str_contains($cmd, 'login status'),
            ARRAY_FILTER_USE_BOTH
        ));
        $this->assertNotEmpty($loginStatusCalls, 'login status probe was never invoked');
        foreach ($loginStatusCalls as $cmd) {
            $this->assertTrue(
                ProbeRecordingDetector::$probeMergeFlags[$cmd],
                "login status probe should merge stderr: {$cmd}"
            );
        }
    }

    public function test_child_env_resolves_home_on_windows_via_userprofile(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-specific HOME resolution');
        }

        // Force HOME blank so the Windows fallback chain has to fire.
        putenv('HOME');
        $env = ProbeRecordingDetector::callChildEnv();

        $this->assertNotEmpty($env['HOME'] ?? '', 'HOME should resolve via USERPROFILE');
        $this->assertSame($env['HOME'], $env['USERPROFILE'] ?? null, 'USERPROFILE mirror missing');
        $this->assertNotEmpty($env['USERNAME'] ?? '', 'USERNAME should be propagated');
        $this->assertNotEmpty($env['SystemRoot'] ?? '', 'SystemRoot must be passed through on Windows');
    }

    public function test_child_env_passes_through_xdg_on_posix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('XDG vars are POSIX-only');
        }

        putenv('XDG_CONFIG_HOME=/tmp/xdg-test');
        try {
            $env = ProbeRecordingDetector::callChildEnv();
            $this->assertSame('/tmp/xdg-test', $env['XDG_CONFIG_HOME'] ?? null);
        } finally {
            putenv('XDG_CONFIG_HOME');
        }
    }

    public function test_path_candidates_are_platform_specific(): void
    {
        $candidates = ProbeRecordingDetector::callPathCandidates('claude');

        if (PHP_OS_FAMILY === 'Windows') {
            // Must include npm-global with .cmd extension and at least one
            // non-npm Windows location (Scoop / Chocolatey / Program Files).
            $haveNpmCmd = false;
            $haveExtra  = false;
            foreach ($candidates as $c) {
                if (str_contains($c, '/npm/claude.cmd')) $haveNpmCmd = true;
                if (str_contains($c, 'chocolatey') || str_contains($c, 'scoop') || str_contains($c, 'Program')) {
                    $haveExtra = true;
                }
            }
            $this->assertTrue($haveNpmCmd, 'Windows candidates must probe %APPDATA%/npm/claude.cmd');
            $this->assertTrue($haveExtra, 'Windows candidates must include Scoop/Chocolatey/Program Files');
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $this->assertContains('/opt/homebrew/bin/claude', $candidates);
            $this->assertContains('/opt/local/bin/claude', $candidates, 'macOS must probe MacPorts');
        } else {
            $this->assertContains('/snap/bin/claude', $candidates, 'Linux must probe Snap');
            $this->assertContains('/home/linuxbrew/.linuxbrew/bin/claude', $candidates);
        }
    }
}

/**
 * Subclass that records every safeProbeOutput call without spawning a
 * real process. Lets us assert on the command strings + flags the
 * detector would have used.
 */
final class ProbeRecordingDetector extends CliStatusDetector
{
    /** @var array<string,string> command → fake stdout */
    public static array $probeReturns = [];
    /** @var array<string,bool> command → mergeStderr flag */
    public static array $probeMergeFlags = [];
    /** @var string[] */
    public static array $probeCommands = [];

    public static function callDetectAuth(string $binary, string $path): ?array
    {
        // Reflection target must be the subclass — that's what anchors
        // PHP's late static binding so `static::safeProbeOutput` inside
        // detectAuth() dispatches to our override below.
        $m = new \ReflectionMethod(self::class, 'detectAuth');
        $m->setAccessible(true);
        return $m->invoke(null, $binary, $path);
    }

    public static function callChildEnv(): array
    {
        $m = new \ReflectionMethod(self::class, 'childEnv');
        $m->setAccessible(true);
        return $m->invoke(null);
    }

    public static function callPathCandidates(string $binary): array
    {
        $env = self::callChildEnv();
        $method = match (PHP_OS_FAMILY) {
            'Windows' => 'windowsPathCandidates',
            'Darwin'  => 'macPathCandidates',
            default   => 'linuxPathCandidates',
        };
        $m = new \ReflectionMethod(self::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, $binary, $env);
    }

    protected static function safeProbeOutput(string $command, array $env, int $timeoutSeconds, bool $mergeStderr = false): ?string
    {
        self::$probeCommands[] = $command;
        self::$probeMergeFlags[$command] = $mergeStderr;
        return self::$probeReturns[$command] ?? null;
    }
}
