<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\ProviderTypeRegistry;
use SuperAICore\Support\ProviderTypeDescriptor;

final class ProviderTypeRegistryTest extends TestCase
{
    public function test_bundles_all_bundled_provider_types(): void
    {
        $registry = new ProviderTypeRegistry();
        $types = array_keys($registry->all());

        sort($types);
        $expected = [
            AiProvider::TYPE_ANTHROPIC,
            AiProvider::TYPE_ANTHROPIC_PROXY,
            AiProvider::TYPE_BEDROCK,
            AiProvider::TYPE_BUILTIN,
            AiProvider::TYPE_GOOGLE_AI,
            AiProvider::TYPE_KIRO_API,
            AiProvider::TYPE_LMSTUDIO,
            AiProvider::TYPE_MOONSHOT_BUILTIN,
            AiProvider::TYPE_OPENAI,
            AiProvider::TYPE_OPENAI_COMPATIBLE,
            AiProvider::TYPE_OPENAI_RESPONSES,
            AiProvider::TYPE_VERTEX,
        ];
        sort($expected);
        $this->assertSame($expected, $types);
    }

    public function test_for_backend_filters_by_allowed_backends(): void
    {
        $registry = new ProviderTypeRegistry();

        $claudeTypes = array_keys($registry->forBackend(AiProvider::BACKEND_CLAUDE));
        sort($claudeTypes);
        // Claude accepts builtin + anthropic family + bedrock + vertex.
        // Does NOT accept openai, google-ai, kiro-api.
        $this->assertContains(AiProvider::TYPE_BUILTIN, $claudeTypes);
        $this->assertContains(AiProvider::TYPE_ANTHROPIC, $claudeTypes);
        $this->assertContains(AiProvider::TYPE_ANTHROPIC_PROXY, $claudeTypes);
        $this->assertContains(AiProvider::TYPE_BEDROCK, $claudeTypes);
        $this->assertContains(AiProvider::TYPE_VERTEX, $claudeTypes);
        $this->assertNotContains(AiProvider::TYPE_OPENAI, $claudeTypes);
        $this->assertNotContains(AiProvider::TYPE_GOOGLE_AI, $claudeTypes);
        $this->assertNotContains(AiProvider::TYPE_KIRO_API, $claudeTypes);

        $kiroTypes = array_keys($registry->forBackend(AiProvider::BACKEND_KIRO));
        $this->assertSame([AiProvider::TYPE_BUILTIN, AiProvider::TYPE_KIRO_API], $kiroTypes);
    }

    public function test_kiro_api_descriptor_has_expected_shape(): void
    {
        $registry = new ProviderTypeRegistry();
        $descriptor = $registry->get(AiProvider::TYPE_KIRO_API);

        $this->assertInstanceOf(ProviderTypeDescriptor::class, $descriptor);
        $this->assertSame('KIRO_API_KEY', $descriptor->envKey);
        $this->assertNull($descriptor->baseUrlEnv);
        $this->assertSame(['api_key'], $descriptor->fields);
        $this->assertTrue($descriptor->needsApiKey);
        $this->assertFalse($descriptor->needsBaseUrl);
        $this->assertSame([AiProvider::BACKEND_KIRO], $descriptor->allowedBackends);
    }

    public function test_bedrock_descriptor_carries_env_extras_and_backend_flag(): void
    {
        $registry = new ProviderTypeRegistry();
        $descriptor = $registry->get(AiProvider::TYPE_BEDROCK);

        // Env extras map env-var name → extra_config key (not api_key).
        $this->assertSame('access_key_id',    $descriptor->envExtras['AWS_ACCESS_KEY_ID']);
        $this->assertSame('secret_access_key', $descriptor->envExtras['AWS_SECRET_ACCESS_KEY']);
        $this->assertSame('region',           $descriptor->envExtras['AWS_REGION']);

        // Claude backend needs the bedrock sentinel flag.
        $this->assertSame(
            ['CLAUDE_CODE_USE_BEDROCK' => '1'],
            $descriptor->backendEnvFlags[AiProvider::BACKEND_CLAUDE] ?? null
        );
    }

    public function test_host_config_override_rebrands_label_key(): void
    {
        // Host app (e.g. SuperTeam) wants to keep its own lang keys
        // without restating the full descriptor.
        $registry = new ProviderTypeRegistry([
            AiProvider::TYPE_ANTHROPIC => [
                'label_key' => 'integrations.ai_provider_anthropic',
                'icon'      => 'bi-custom-icon',
            ],
        ]);
        $descriptor = $registry->get(AiProvider::TYPE_ANTHROPIC);

        $this->assertSame('integrations.ai_provider_anthropic', $descriptor->labelKey);
        $this->assertSame('bi-custom-icon', $descriptor->icon);
        // Non-overridden fields survive the merge.
        $this->assertSame('ANTHROPIC_API_KEY', $descriptor->envKey);
        $this->assertSame(['api_key'], $descriptor->fields);
    }

    public function test_host_config_can_add_brand_new_type(): void
    {
        // Simulate SuperAgent 0.8 adding xAI support via host config.
        $registry = new ProviderTypeRegistry([
            'xai-api' => [
                'label_key'        => 'integrations.ai_provider_xai',
                'icon'             => 'bi-x-lg',
                'fields'           => ['api_key'],
                'default_backend'  => AiProvider::BACKEND_SUPERAGENT,
                'allowed_backends' => [AiProvider::BACKEND_SUPERAGENT],
                'env_key'          => 'XAI_API_KEY',
            ],
        ]);

        $this->assertNotNull($registry->get('xai-api'));
        $this->assertTrue($registry->requiresApiKey('xai-api'));
        $this->assertSame('XAI_API_KEY', $registry->get('xai-api')->envKey);
        $this->assertContains('xai-api', array_keys($registry->forBackend(AiProvider::BACKEND_SUPERAGENT)));
    }

    public function test_requires_api_key_matches_bundled_intuition(): void
    {
        $registry = new ProviderTypeRegistry();

        $this->assertTrue($registry->requiresApiKey(AiProvider::TYPE_ANTHROPIC));
        $this->assertTrue($registry->requiresApiKey(AiProvider::TYPE_OPENAI));
        $this->assertTrue($registry->requiresApiKey(AiProvider::TYPE_GOOGLE_AI));
        $this->assertTrue($registry->requiresApiKey(AiProvider::TYPE_KIRO_API));

        $this->assertFalse($registry->requiresApiKey(AiProvider::TYPE_BUILTIN));
        $this->assertFalse($registry->requiresApiKey(AiProvider::TYPE_BEDROCK));
        $this->assertFalse($registry->requiresApiKey(AiProvider::TYPE_VERTEX));
    }

    public function test_openai_responses_descriptor_maps_to_sdk_provider(): void
    {
        $registry = new ProviderTypeRegistry();
        $descriptor = $registry->get(AiProvider::TYPE_OPENAI_RESPONSES);

        $this->assertInstanceOf(ProviderTypeDescriptor::class, $descriptor);
        $this->assertSame('openai-responses', $descriptor->sdkProvider);
        // api_key optional — ChatGPT OAuth routes on access_token, Azure
        // detection relies on base_url patterns the SDK inspects.
        $this->assertFalse($descriptor->needsApiKey);
        $this->assertSame([AiProvider::BACKEND_SUPERAGENT], $descriptor->allowedBackends);
    }

    public function test_lmstudio_descriptor_maps_to_local_server(): void
    {
        $registry = new ProviderTypeRegistry();
        $descriptor = $registry->get(AiProvider::TYPE_LMSTUDIO);

        $this->assertSame('lmstudio', $descriptor->sdkProvider);
        $this->assertFalse($descriptor->needsApiKey);
        $this->assertFalse($descriptor->needsBaseUrl);
    }

    public function test_anthropic_proxy_sdk_provider_is_anthropic(): void
    {
        // `anthropic-proxy` is a BYO-base-url wrapper — SDK key is the base
        // provider. Previously SuperAgentBackend defaulted to 'anthropic'
        // regardless; the descriptor now makes the mapping explicit.
        $registry = new ProviderTypeRegistry();
        $this->assertSame('anthropic', $registry->get(AiProvider::TYPE_ANTHROPIC_PROXY)->sdkProvider);
        $this->assertSame('openai',    $registry->get(AiProvider::TYPE_OPENAI_COMPATIBLE)->sdkProvider);
    }

    public function test_http_headers_descriptor_fields_default_to_empty(): void
    {
        $registry = new ProviderTypeRegistry();
        $this->assertSame([], $registry->get(AiProvider::TYPE_OPENAI)->httpHeaders);
        $this->assertSame([], $registry->get(AiProvider::TYPE_OPENAI)->envHttpHeaders);
    }

    public function test_host_config_can_declare_http_headers(): void
    {
        $registry = new ProviderTypeRegistry([
            AiProvider::TYPE_OPENAI => [
                'http_headers'     => ['X-App' => 'superaicore'],
                'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
            ],
        ]);
        $descriptor = $registry->get(AiProvider::TYPE_OPENAI);

        $this->assertSame(['X-App' => 'superaicore'], $descriptor->httpHeaders);
        $this->assertSame(['OpenAI-Project' => 'OPENAI_PROJECT'], $descriptor->envHttpHeaders);
    }

    public function test_toarray_preserves_legacy_blade_shape(): void
    {
        // SuperTeam's existing Blade templates iterate `$type['label_key']`
        // and `$type['fields']`. toArray() must keep those keys so the
        // migration doesn't require view changes.
        $registry = new ProviderTypeRegistry();
        $data = $registry->get(AiProvider::TYPE_ANTHROPIC)->toArray();

        $this->assertArrayHasKey('label_key', $data);
        $this->assertArrayHasKey('desc_key', $data);
        $this->assertArrayHasKey('icon', $data);
        $this->assertArrayHasKey('fields', $data);
        $this->assertArrayHasKey('backend', $data);
        $this->assertArrayHasKey('allowed_backends', $data);
    }
}
