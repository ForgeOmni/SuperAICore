<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\KiroModelResolver;

final class KiroModelResolverTest extends TestCase
{
    /**
     * Real shape returned by `kiro-cli chat --list-models --format
     * json-pretty` (captured 2026-04-21 against Kiro CLI 2.x). Any field
     * rename upstream will be caught by this assertion before it reaches
     * a production host.
     */
    private const LIVE_PAYLOAD = <<<'JSON'
    {
      "models": [
        {
          "model_name": "auto",
          "description": "Models chosen by task for optimal usage and consistent quality",
          "model_id": "auto",
          "context_window_tokens": 1000000,
          "rate_multiplier": 1.0,
          "rate_unit": "Credit"
        },
        {
          "model_name": "claude-opus-4.6",
          "description": "The Claude Opus 4.6 model",
          "model_id": "claude-opus-4.6",
          "context_window_tokens": 1000000,
          "rate_multiplier": 2.2,
          "rate_unit": "Credit"
        },
        {
          "model_name": "claude-sonnet-4.6",
          "description": "The latest Claude Sonnet model with 1M context window",
          "model_id": "claude-sonnet-4.6",
          "context_window_tokens": 1000000,
          "rate_multiplier": 1.3,
          "rate_unit": "Credit"
        },
        {
          "model_name": "claude-haiku-4.5",
          "description": "The latest Claude Haiku model",
          "model_id": "claude-haiku-4.5",
          "context_window_tokens": 200000,
          "rate_multiplier": 0.4,
          "rate_unit": "Credit"
        },
        {
          "model_name": "deepseek-3.2",
          "description": "Experimental preview of DeepSeek V3.2",
          "model_id": "deepseek-3.2",
          "context_window_tokens": 164000,
          "rate_multiplier": 0.25,
          "rate_unit": "Credit"
        },
        {
          "model_name": "qwen3-coder-next",
          "description": "Experimental preview of Qwen3 Coder Next",
          "model_id": "qwen3-coder-next",
          "context_window_tokens": 256000,
          "rate_multiplier": 0.05,
          "rate_unit": "Credit"
        }
      ],
      "default_model": "auto"
    }
    JSON;

    public function test_parses_live_list_models_payload(): void
    {
        $parsed = KiroModelResolver::parseListModels(self::LIVE_PAYLOAD);
        $this->assertIsArray($parsed);
        $this->assertCount(6, $parsed);

        $slugs = array_column($parsed, 'slug');
        $this->assertContains('auto',             $slugs);
        $this->assertContains('claude-sonnet-4.6', $slugs);
        $this->assertContains('deepseek-3.2',     $slugs);
        $this->assertContains('qwen3-coder-next', $slugs);

        $byId = [];
        foreach ($parsed as $row) {
            $byId[$row['slug']] = $row;
        }

        $this->assertSame('sonnet',   $byId['claude-sonnet-4.6']['family']);
        $this->assertSame('haiku',    $byId['claude-haiku-4.5']['family']);
        $this->assertSame('deepseek', $byId['deepseek-3.2']['family']);
        $this->assertSame('qwen',     $byId['qwen3-coder-next']['family']);
        $this->assertNull($byId['auto']['family']);

        $this->assertSame(
            'The latest Claude Sonnet model with 1M context window',
            $byId['claude-sonnet-4.6']['display_name']
        );
    }

    public function test_parse_returns_null_on_malformed_input(): void
    {
        $this->assertNull(KiroModelResolver::parseListModels(''));
        $this->assertNull(KiroModelResolver::parseListModels('not json'));
        $this->assertNull(KiroModelResolver::parseListModels('{"other":"shape"}'));
        $this->assertNull(KiroModelResolver::parseListModels('{"models":"not-an-array"}'));
    }

    public function test_parse_skips_rows_missing_ids(): void
    {
        $json = '{"models":[{"description":"anon"},{"model_id":"glm-5","description":"GLM-5"}]}';
        $parsed = KiroModelResolver::parseListModels($json);
        $this->assertIsArray($parsed);
        $this->assertCount(1, $parsed);
        $this->assertSame('glm-5', $parsed[0]['slug']);
    }

    public function test_static_fallback_covers_all_known_kiro_models(): void
    {
        // Even when kiro-cli isn't on PATH (fresh install, CI environment),
        // catalog() must return something usable. Assert the fallback
        // contains the non-Anthropic families so users can still select
        // deepseek/minimax/glm/qwen from the picker.
        $catalog = KiroModelResolver::catalog();
        $slugs = array_column($catalog, 'slug');

        $this->assertContains('auto',              $slugs);
        $this->assertContains('claude-sonnet-4.6', $slugs);
        $this->assertContains('deepseek-3.2',      $slugs);
        $this->assertContains('minimax-m2.5',      $slugs);
        $this->assertContains('glm-5',             $slugs);
        $this->assertContains('qwen3-coder-next',  $slugs);
    }

    public function test_resolve_translates_dash_format_to_dot(): void
    {
        $this->assertSame('claude-sonnet-4.6', KiroModelResolver::resolve('claude-sonnet-4-6'));
        $this->assertSame('claude-opus-4.6',   KiroModelResolver::resolve('claude-opus-4-6'));
        $this->assertSame('claude-haiku-4.5',  KiroModelResolver::resolve('claude-haiku-4-5'));
    }

    public function test_resolve_strips_context_tag_and_date_suffix(): void
    {
        // Claude CLI emits `claude-opus-4-7[1m]` for its 1M-context variant
        // — Kiro has no 4.7, so we fall back to the family default 4.6.
        $this->assertSame(
            'claude-opus-4.6',
            KiroModelResolver::resolve('claude-opus-4-7[1m]')
        );
        // `claude-sonnet-4-5-20241022` strips its date, dash→dot converts,
        // and `claude-sonnet-4.5` IS in Kiro's catalog — keep the minor
        // rather than falling back to 4.6 (more faithful to the caller).
        $this->assertSame(
            'claude-sonnet-4.5',
            KiroModelResolver::resolve('claude-sonnet-4-5-20241022')
        );
    }

    public function test_resolve_passes_through_valid_ids(): void
    {
        $this->assertSame('auto',              KiroModelResolver::resolve('auto'));
        $this->assertSame('claude-sonnet-4.6', KiroModelResolver::resolve('claude-sonnet-4.6'));
        $this->assertSame('deepseek-3.2',      KiroModelResolver::resolve('deepseek-3.2'));
        $this->assertSame('glm-5',             KiroModelResolver::resolve('glm-5'));
    }

    public function test_resolve_handles_family_aliases(): void
    {
        $this->assertSame('claude-sonnet-4.6', KiroModelResolver::resolve('sonnet'));
        $this->assertSame('claude-opus-4.6',   KiroModelResolver::resolve('opus'));
        $this->assertSame('claude-haiku-4.5',  KiroModelResolver::resolve('haiku'));
    }

    public function test_resolve_returns_null_for_empty_input(): void
    {
        $this->assertNull(KiroModelResolver::resolve(null));
        $this->assertNull(KiroModelResolver::resolve(''));
    }

    public function test_resolve_passes_unknown_ids_through_unchanged(): void
    {
        // Don't silently substitute — let kiro-cli emit its own error.
        $this->assertSame('gpt-9000', KiroModelResolver::resolve('gpt-9000'));
    }
}
