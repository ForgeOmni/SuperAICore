<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\AliasRouter;

final class AliasRouterTest extends TestCase
{
    private function router(array $configAliases = []): AliasRouter
    {
        return new AliasRouter(null, $configAliases, 'anthropic_api');
    }

    public function test_builtin_alias_resolves_backend_and_model(): void
    {
        $route = $this->router()->resolve('opus');
        $this->assertSame('builtin', $route['source']);
        $this->assertSame([['backend' => 'claude_cli', 'model' => 'opus']], $route['candidates']);
    }

    public function test_builtin_alias_is_case_insensitive(): void
    {
        $route = $this->router()->resolve('KIMI');
        $this->assertSame('builtin', $route['source']);
        $this->assertSame('kimi_cli', $route['candidates'][0]['backend']);
    }

    public function test_grok_composer_model_infers_grok_backend_not_cursor(): void
    {
        // `grok-composer-2.5-fast` is a real Grok CLI model id that CONTAINS
        // the substring "composer"; the INFERENCE map must check `grok`
        // before `composer` so it routes to grok_cli, not cursor_cli.
        $route = $this->router()->resolve('grok-composer-2.5-fast');
        $this->assertSame('inference', $route['source']);
        $this->assertSame('grok_cli', $route['candidates'][0]['backend']);
        $this->assertSame('grok-composer-2.5-fast', $route['candidates'][0]['model']);
    }

    public function test_bare_composer_model_still_infers_cursor(): void
    {
        // A cursor composer id (no "grok") must still land on cursor_cli.
        $route = $this->router()->resolve('composer-2.5');
        $this->assertSame('inference', $route['source']);
        $this->assertSame('cursor_cli', $route['candidates'][0]['backend']);
    }

    public function test_config_alias_overrides_builtin(): void
    {
        $route = $this->router([
            'opus' => [['backend' => 'superagent', 'model' => 'claude-opus-4-8']],
        ])->resolve('opus');
        $this->assertSame('config', $route['source']);
        $this->assertSame('superagent', $route['candidates'][0]['backend']);
    }

    public function test_config_alias_accepts_compact_string_forms(): void
    {
        $router = $this->router([
            'mimo' => 'superagent:mimo-v2.5-pro',
            'review' => ['claude_cli:opus', 'gemini_cli:pro'],
        ]);

        $mimo = $router->resolve('mimo');
        $this->assertSame([['backend' => 'superagent', 'model' => 'mimo-v2.5-pro']], $mimo['candidates']);

        $review = $router->resolve('review');
        $this->assertCount(2, $review['candidates']);
        $this->assertSame('gemini_cli', $review['candidates'][1]['backend']);
        $this->assertSame('pro', $review['candidates'][1]['model']);
    }

    public function test_config_alias_accepts_single_candidate_map(): void
    {
        $route = $this->router([
            'fast' => ['backend' => 'gemini_cli', 'model' => 'flash'],
        ])->resolve('fast');
        $this->assertSame([['backend' => 'gemini_cli', 'model' => 'flash']], $route['candidates']);
    }

    public function test_backend_name_passes_through(): void
    {
        $route = $this->router()->resolve('codex_cli');
        $this->assertSame('backend', $route['source']);
        $this->assertSame([['backend' => 'codex_cli', 'model' => null]], $route['candidates']);
    }

    public function test_model_id_infers_engine_backend(): void
    {
        $route = $this->router()->resolve('claude-opus-4-8');
        $this->assertSame('inference', $route['source']);
        $this->assertSame('claude_cli', $route['candidates'][0]['backend']);
        // Original casing preserved — model ids belong to the engine.
        $this->assertSame('claude-opus-4-8', $route['candidates'][0]['model']);
    }

    public function test_unknown_target_falls_back_to_default_backend(): void
    {
        $route = $this->router()->resolve('deepseek-v4-pro');
        $this->assertSame('default', $route['source']);
        $this->assertSame('anthropic_api', $route['candidates'][0]['backend']);
        $this->assertSame('deepseek-v4-pro', $route['candidates'][0]['model']);
    }

    public function test_all_merges_config_over_builtin(): void
    {
        $all = $this->router(['opus' => 'superagent:claude-opus-4-8'])->all();
        $this->assertSame('superagent', $all['opus'][0]['backend']);
        $this->assertArrayHasKey('kimi', $all);
    }
}
