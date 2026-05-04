<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Guidance;

use PHPUnit\Framework\TestCase;
use SuperAICore\Guidance\Gates\DestructiveCommandScanner;
use SuperAICore\Guidance\Gates\SecretScanner;

/**
 * Mirrors ruflo's `wasm-kernel/src/gates.rs` test cases plus a few PHP-
 * specific edge cases. Asserts the same redaction shape so audit logs
 * remain interoperable with the Rust-emitted ones.
 */
final class GatesTest extends TestCase
{
    // ── SecretScanner ────────────────────────────────────────────

    public function test_scan_secrets_api_key_redacted(): void
    {
        $matches = (new SecretScanner())->scan('api_key = "sk-abcdefghij1234567890"');
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('****', $matches[0]);
    }

    public function test_scan_secrets_private_key_block(): void
    {
        $matches = (new SecretScanner())->scan("-----BEGIN RSA PRIVATE KEY-----\nMIIE...");
        $this->assertNotEmpty($matches);
    }

    public function test_scan_secrets_clean_returns_empty(): void
    {
        $matches = (new SecretScanner())->scan('Just a normal string with no secrets');
        $this->assertSame([], $matches);
    }

    public function test_scan_secrets_github_pat(): void
    {
        $pat = 'ghp_' . str_repeat('a', 36);
        $matches = (new SecretScanner())->scan("env: GITHUB_TOKEN={$pat}");
        $this->assertNotEmpty($matches);
    }

    public function test_scan_secrets_aws_key(): void
    {
        $matches = (new SecretScanner())->scan('use AKIAIOSFODNN7EXAMPLE in your config');
        $this->assertNotEmpty($matches);
    }

    public function test_scan_secrets_npm_token(): void
    {
        $tok = 'npm_' . str_repeat('z', 36);
        $matches = (new SecretScanner())->scan("//registry.npmjs.org/:_authToken={$tok}");
        $this->assertNotEmpty($matches);
    }

    public function test_redact_keeps_first_and_last_4_chars(): void
    {
        $val = 'sk-' . str_repeat('x', 25); // 28 chars
        $r = SecretScanner::redact($val);
        $this->assertStringStartsWith('sk-x', $r);
        $this->assertStringEndsWith('xxxx', $r);
        $this->assertSame(strlen($val), strlen($r));
        $this->assertSame(20, substr_count($r, '*'));
    }

    public function test_redact_short_match_fully_masked(): void
    {
        $r = SecretScanner::redact('short');
        $this->assertSame('*****', $r);
    }

    public function test_detect_returns_offsets_in_source_order(): void
    {
        $haystack = 'pre AKIAIOSFODNN7EXAMPLE mid sk-abcdef1234567890ZZZZ end';
        $det = (new SecretScanner())->detect($haystack);
        $this->assertCount(2, $det);
        $this->assertLessThan($det[1]['offset'], $det[0]['offset']);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $det[0]['matched']);
    }

    // ── DestructiveCommandScanner ────────────────────────────────

    public function test_destructive_rm_rf(): void
    {
        $s = new DestructiveCommandScanner();
        $this->assertNotNull($s->firstMatch('rm -rf /'));
        $this->assertNotNull($s->firstMatch('  sudo rm -r /tmp/foo'));
        $this->assertTrue($s->isDestructive('rm -rf node_modules'));
    }

    public function test_destructive_drop_table(): void
    {
        $this->assertNotNull((new DestructiveCommandScanner())->firstMatch('DROP TABLE users'));
    }

    public function test_destructive_force_push(): void
    {
        $this->assertNotNull((new DestructiveCommandScanner())->firstMatch('git push origin main --force'));
    }

    public function test_destructive_kubectl_delete_all(): void
    {
        $this->assertNotNull((new DestructiveCommandScanner())->firstMatch('kubectl delete --all -n production'));
    }

    public function test_destructive_clean_command_passes(): void
    {
        $s = new DestructiveCommandScanner();
        $this->assertNull($s->firstMatch("git commit -m 'hello'"));
        $this->assertFalse($s->isDestructive('SELECT * FROM users WHERE id = 1'));
    }

    public function test_destructive_all_matches_in_compound(): void
    {
        $s = new DestructiveCommandScanner();
        $hits = $s->allMatches('rm -rf foo && DROP TABLE bar && git reset --hard');
        $this->assertGreaterThanOrEqual(3, count($hits));
    }
}
