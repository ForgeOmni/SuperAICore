<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\KiroCliBackend;

final class KiroCliBackendTest extends TestCase
{
    public function test_parses_plain_text_with_trailing_credits_summary(): void
    {
        $backend = new KiroCliBackend();

        // Shape modelled on actual `kiro-cli chat --no-interactive` output
        // documented in the Kiro 2.0 headless-mode launch post.
        $stdout = implode("\n", [
            "I checked the directory and found 3 PHP files:",
            "- src/Foo.php",
            "- src/Bar.php",
            "- src/Baz.php",
            "",
            "▸ Credits: 0.39 • Time: 22s",
        ]);

        $parsed = $backend->parseOutput($stdout);

        $this->assertNotNull($parsed);
        $this->assertStringContainsString('src/Bar.php', $parsed['text']);
        $this->assertStringNotContainsString('Credits:', $parsed['text']);
        $this->assertSame(0.39, $parsed['credits']);
        $this->assertSame(22,   $parsed['duration_s']);
    }

    public function test_accepts_plain_ascii_summary_marker(): void
    {
        // Some terminals / non-UTF outputs render `▸` as `>` or strip it.
        $backend = new KiroCliBackend();
        $stdout  = "Done.\n\n> Credits: 1.20 • Time: 7s\n";

        $parsed = $backend->parseOutput($stdout);

        $this->assertSame('Done.', $parsed['text']);
        $this->assertSame(1.20,    $parsed['credits']);
        $this->assertSame(7,       $parsed['duration_s']);
    }

    public function test_missing_summary_returns_body_with_zero_usage(): void
    {
        // Early failure / help text / short builds without the tail — we
        // still return the body so callers can surface it.
        $backend = new KiroCliBackend();

        $parsed = $backend->parseOutput("unexpected output with no summary line");

        $this->assertSame('unexpected output with no summary line', $parsed['text']);
        $this->assertSame(0.0, $parsed['credits']);
        $this->assertSame(0,   $parsed['duration_s']);
    }

    public function test_returns_null_on_empty_output(): void
    {
        $backend = new KiroCliBackend();
        $this->assertNull($backend->parseOutput(''));
        $this->assertNull($backend->parseOutput("   \n\n"));
    }
}
