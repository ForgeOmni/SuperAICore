<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\ProviderEnvBuilder;
use SuperAICore\Services\ProviderTypeRegistry;

/**
 * Exercises buildEnvFromConfig — the path Dispatcher-driven backends use
 * when they only have a provider_config array in hand. The AiProvider-
 * model variant is covered indirectly because it shares the descriptor
 * lookup but needs a DB row to exercise; the shape is identical.
 */
final class ProviderEnvBuilderTest extends TestCase
{
    private function builder(array $overrides = []): ProviderEnvBuilder
    {
        return new ProviderEnvBuilder(new ProviderTypeRegistry($overrides));
    }

    public function test_anthropic_api_key_goes_into_anthropic_api_key_env(): void
    {
        $env = $this->builder()->buildEnvFromConfig([
            'type'    => AiProvider::TYPE_ANTHROPIC,
            'api_key' => 'sk-ant-abc',
        ]);

        $this->assertSame(['ANTHROPIC_API_KEY' => 'sk-ant-abc'], $env);
    }

    public function test_anthropic_proxy_sets_both_api_key_and_base_url(): void
    {
        $env = $this->builder()->buildEnvFromConfig([
            'type'     => AiProvider::TYPE_ANTHROPIC_PROXY,
            'api_key'  => 'sk-ant-proxy',
            'base_url' => 'https://proxy.example/v1/',
        ]);

        $this->assertSame('sk-ant-proxy', $env['ANTHROPIC_API_KEY']);
        $this->assertSame('https://proxy.example/v1', $env['ANTHROPIC_BASE_URL']); // trailing slash stripped
    }

    public function test_bedrock_spreads_extra_config_into_aws_env_and_flag(): void
    {
        $env = $this->builder()->buildEnvFromConfig([
            'type'    => AiProvider::TYPE_BEDROCK,
            'backend' => AiProvider::BACKEND_CLAUDE,
            'extra_config' => [
                'access_key_id'     => 'AKIA_…',
                'secret_access_key' => 'wJalrX…',
                'region'            => 'us-east-1',
            ],
        ]);

        $this->assertSame('AKIA_…',     $env['AWS_ACCESS_KEY_ID']);
        $this->assertSame('wJalrX…',    $env['AWS_SECRET_ACCESS_KEY']);
        $this->assertSame('us-east-1',  $env['AWS_REGION']);
        $this->assertSame('1',          $env['CLAUDE_CODE_USE_BEDROCK']);
        // No api_key gets set — bedrock auth is all via the extras.
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
    }

    public function test_vertex_flag_only_fires_on_claude_backend(): void
    {
        $gemini = $this->builder()->buildEnvFromConfig([
            'type'    => AiProvider::TYPE_VERTEX,
            'backend' => AiProvider::BACKEND_GEMINI,
            'extra_config' => ['project_id' => 'proj-42', 'region' => 'us-central1'],
        ]);
        $this->assertArrayNotHasKey('CLAUDE_CODE_USE_VERTEX', $gemini);
        $this->assertSame('proj-42', $gemini['GOOGLE_CLOUD_PROJECT']);

        $claude = $this->builder()->buildEnvFromConfig([
            'type'    => AiProvider::TYPE_VERTEX,
            'backend' => AiProvider::BACKEND_CLAUDE,
            'extra_config' => ['project_id' => 'proj-42', 'region' => 'us-central1'],
        ]);
        $this->assertSame('1', $claude['CLAUDE_CODE_USE_VERTEX']);
    }

    public function test_google_ai_flows_key_into_both_env_vars(): void
    {
        // Gemini CLI accepts either GEMINI_API_KEY or GOOGLE_API_KEY — env_extras
        // re-maps the api_key into the secondary slot so both are set.
        $env = $this->builder()->buildEnvFromConfig([
            'type'    => AiProvider::TYPE_GOOGLE_AI,
            'api_key' => 'AIza-test',
        ]);

        $this->assertSame('AIza-test', $env['GEMINI_API_KEY']);
        $this->assertSame('AIza-test', $env['GOOGLE_API_KEY']);
    }

    public function test_openai_compatible_spreads_base_url(): void
    {
        $env = $this->builder()->buildEnvFromConfig([
            'type'     => AiProvider::TYPE_OPENAI_COMPATIBLE,
            'api_key'  => 'sk-groq-abc',
            'base_url' => 'https://api.groq.com/openai',
        ]);

        $this->assertSame('sk-groq-abc', $env['OPENAI_API_KEY']);
        $this->assertSame('https://api.groq.com/openai', $env['OPENAI_BASE_URL']);
    }

    public function test_kiro_api_only_fires_for_kiro_api_type(): void
    {
        $env = $this->builder()->buildEnvFromConfig([
            'type'    => AiProvider::TYPE_KIRO_API,
            'api_key' => 'ksk_xyz',
        ]);
        $this->assertSame(['KIRO_API_KEY' => 'ksk_xyz'], $env);

        // builtin must NOT inject KIRO_API_KEY — the CLI's own session
        // carries the request, and injecting would defeat the login flow.
        $builtin = $this->builder()->buildEnvFromConfig([
            'type' => AiProvider::TYPE_BUILTIN,
        ]);
        $this->assertSame([], $builtin);
    }

    public function test_host_added_type_flows_its_env_key(): void
    {
        // Simulate a host (SuperTeam) adding a new type via config
        // without any SuperAICore code changes.
        $builder = $this->builder([
            'xai-api' => [
                'env_key'          => 'XAI_API_KEY',
                'fields'           => ['api_key'],
                'default_backend'  => AiProvider::BACKEND_SUPERAGENT,
                'allowed_backends' => [AiProvider::BACKEND_SUPERAGENT],
            ],
        ]);

        $env = $builder->buildEnvFromConfig([
            'type'    => 'xai-api',
            'api_key' => 'xai-token-123',
        ]);
        $this->assertSame(['XAI_API_KEY' => 'xai-token-123'], $env);
    }

    public function test_unknown_type_returns_empty_env(): void
    {
        // Don't invent env keys for unknown types — let the dispatcher
        // emit its own error later.
        $env = $this->builder()->buildEnvFromConfig(['type' => 'made-up-type']);
        $this->assertSame([], $env);
    }

    public function test_missing_api_key_skips_envkey_export(): void
    {
        // Descriptor has envKey but provider_config has no api_key —
        // shouldn't export the empty string as the env var.
        $env = $this->builder()->buildEnvFromConfig([
            'type' => AiProvider::TYPE_ANTHROPIC,
        ]);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
    }
}
