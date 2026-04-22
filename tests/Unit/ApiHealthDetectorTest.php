<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\ApiHealthDetector;

/**
 * Exercises the detector's normalisation / filtering layer without hitting
 * the network. The SDK's actual cURL probe is covered by forgeomni/superagent's
 * own test suite — here we just pin the contract our dashboard depends on:
 * every row must carry all four keys, and `filterToConfigured()` must
 * respect both $_ENV and getenv().
 */
final class ApiHealthDetectorTest extends TestCase
{
    /** @var array<string, string|false> snapshot so tearDown can restore env */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'KIMI_API_KEY',
                  'OPENROUTER_API_KEY', 'GEMINI_API_KEY',
                  'QWEN_API_KEY', 'GLM_API_KEY', 'MINIMAX_API_KEY'] as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? false;
            unset($_ENV[$k]);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === false) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
        parent::tearDown();
    }

    public function test_check_returns_all_four_keys_even_when_sdk_omits_some(): void
    {
        // Unknown provider → SDK returns {provider, ok:false, reason:'unknown provider'},
        // no latency_ms. Detector must fill `latency_ms` with null.
        $r = ApiHealthDetector::check('does-not-exist-' . bin2hex(random_bytes(2)));

        $this->assertArrayHasKey('provider',   $r);
        $this->assertArrayHasKey('ok',         $r);
        $this->assertArrayHasKey('latency_ms', $r);
        $this->assertArrayHasKey('reason',     $r);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['latency_ms']);
        $this->assertIsString($r['reason']);
    }

    public function test_check_returns_no_key_reason_without_network_call(): void
    {
        // With ANTHROPIC_API_KEY unset (setUp cleared it), SDK short-circuits
        // before any HTTP attempt — this test proves we don't hit the wire
        // unless a key is present.
        $r = ApiHealthDetector::check('anthropic');

        $this->assertSame('anthropic', $r['provider']);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['latency_ms']);
        $this->assertStringContainsString('API key', (string) $r['reason']);
    }

    public function test_filter_to_configured_drops_providers_without_env_key(): void
    {
        $this->assertSame([], ApiHealthDetector::filterToConfigured(['anthropic', 'openai']));
    }

    public function test_filter_to_configured_reads_from_getenv(): void
    {
        putenv('OPENAI_API_KEY=sk-test');

        $this->assertSame(
            ['openai'],
            ApiHealthDetector::filterToConfigured(['anthropic', 'openai', 'kimi']),
        );
    }

    public function test_filter_to_configured_reads_from_env_superglobal(): void
    {
        $_ENV['KIMI_API_KEY'] = 'ms-test';

        $this->assertSame(
            ['kimi'],
            ApiHealthDetector::filterToConfigured(['kimi', 'openai']),
        );
    }

    public function test_filter_to_configured_ignores_unknown_provider_names(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-test';

        $this->assertSame(
            ['anthropic'],
            ApiHealthDetector::filterToConfigured(['anthropic', 'bogus', 'ollama']),
        );
    }

    public function test_check_many_with_null_uses_env_filtered_default(): void
    {
        // No env keys set (setUp cleared them) → empty result, no probes
        // attempted.
        $this->assertSame([], ApiHealthDetector::checkMany(null));
    }

    public function test_check_many_with_explicit_list_normalises_each_row(): void
    {
        $rows = ApiHealthDetector::checkMany(['anthropic']);

        $this->assertCount(1, $rows);
        $this->assertSame('anthropic', $rows[0]['provider']);
        // Key not set → ok:false, no network call.
        $this->assertFalse($rows[0]['ok']);
    }
}
