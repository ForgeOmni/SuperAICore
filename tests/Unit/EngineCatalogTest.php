<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\EngineCatalog;

final class EngineCatalogTest extends TestCase
{
    public function test_seeds_canonical_engines(): void
    {
        $catalog = new EngineCatalog([]);
        $keys = $catalog->keys();

        $this->assertContains('claude', $keys);
        $this->assertContains('codex', $keys);
        $this->assertContains('gemini', $keys);
        $this->assertContains('copilot', $keys);
        $this->assertContains('superagent', $keys);
    }

    public function test_dispatcher_to_engine_map_includes_copilot(): void
    {
        $catalog = new EngineCatalog([]);
        $map = $catalog->dispatcherToEngineMap();

        $this->assertSame('claude', $map['claude_cli'] ?? null);
        $this->assertSame('claude', $map['anthropic_api'] ?? null);
        $this->assertSame('codex', $map['codex_cli'] ?? null);
        $this->assertSame('gemini', $map['gemini_cli'] ?? null);
        $this->assertSame('copilot', $map['copilot_cli'] ?? null);
        $this->assertSame('superagent', $map['superagent'] ?? null);
    }

    public function test_process_scan_keywords_cover_engine_keys_and_binaries(): void
    {
        $catalog = new EngineCatalog([]);
        $kw = $catalog->processScanKeywords();

        // Engine short keys
        $this->assertContains('claude', $kw);
        $this->assertContains('copilot', $kw);
        // CLI binary names (same as keys here, but the contract is they're included)
        $this->assertContains('codex', $kw);
        $this->assertContains('gemini', $kw);
    }

    public function test_models_for_known_engine_returns_configured_list(): void
    {
        $catalog = new EngineCatalog([]);
        $copilotModels = $catalog->modelsFor('copilot');

        // Copilot uses dot-separated model IDs (not Claude CLI's dashes).
        $this->assertNotEmpty($copilotModels);
        $this->assertContains('claude-sonnet-4.6', $copilotModels);
        $this->assertContains('gpt-5.1', $copilotModels);
    }

    public function test_models_for_unknown_engine_returns_empty(): void
    {
        $catalog = new EngineCatalog([]);
        $this->assertSame([], $catalog->modelsFor('does-not-exist'));
    }

    public function test_overrides_replace_seeded_fields_per_engine(): void
    {
        $catalog = new EngineCatalog([
            'claude' => [
                'available_models' => ['custom-model-1', 'custom-model-2'],
            ],
        ]);

        $this->assertSame(['custom-model-1', 'custom-model-2'], $catalog->modelsFor('claude'));
        // Other fields stay seeded
        $this->assertSame('Claude Code', $catalog->get('claude')->label);
    }

    public function test_overrides_can_register_brand_new_engine(): void
    {
        $catalog = new EngineCatalog([
            'mistral' => [
                'label' => 'Mistral',
                'icon'  => 'shield',
                'dispatcher_backends' => ['mistral_api'],
                'available_models' => ['mistral-large'],
                'is_cli' => false,
            ],
        ]);

        $engine = $catalog->get('mistral');
        $this->assertNotNull($engine);
        $this->assertSame('Mistral', $engine->label);
        $this->assertContains('mistral-large', $engine->availableModels);
        $this->assertSame('mistral', $catalog->dispatcherToEngineMap()['mistral_api']);
    }

    public function test_provider_types_are_derived_from_aiprovider_matrix(): void
    {
        $catalog = new EngineCatalog([]);
        $copilot = $catalog->get('copilot');

        $this->assertSame(AiProvider::typesForBackend('copilot'), $copilot->providerTypes);
        $this->assertContains(AiProvider::TYPE_BUILTIN, $copilot->providerTypes);
    }

    public function test_model_options_claude_uses_resolver_family_aliases(): void
    {
        $catalog = new EngineCatalog([]);
        $opts = $catalog->modelOptions('claude');

        // Placeholder always first
        $this->assertSame('— 继承默认 —', $opts['']);
        // Family aliases from ClaudeModelResolver::families()
        $this->assertArrayHasKey('sonnet', $opts);
        $this->assertStringContainsString('Sonnet', $opts['sonnet']);
        // Full catalog entries present too
        $this->assertArrayHasKey('claude-sonnet-4-6', $opts);
    }

    public function test_model_options_copilot_uses_resolver_family_aliases(): void
    {
        $catalog = new EngineCatalog([]);
        $opts = $catalog->modelOptions('copilot');

        // CopilotModelResolver ships family aliases alongside the full catalog.
        $this->assertSame('— 继承默认 —', $opts['']);
        $this->assertArrayHasKey('claude-sonnet-4.6', $opts);
        $this->assertArrayHasKey('gpt-5.1', $opts);
        // At least one family alias should be present (shape, not exact key).
        $hasFamily = array_filter(
            array_keys($opts),
            fn ($k) => $k !== '' && !str_contains($k, '.') && !str_contains($k, '-'),
        );
        $this->assertNotEmpty($hasFamily, 'Expected at least one family alias key in copilot options');
    }

    public function test_model_options_host_registered_engine_uses_available_models(): void
    {
        $catalog = new EngineCatalog([
            'mistral' => [
                'label' => 'Mistral',
                'available_models' => ['mistral-large', 'mistral-small'],
            ],
        ]);
        $opts = $catalog->modelOptions('mistral');

        $this->assertArrayHasKey('mistral-large', $opts);
        $this->assertArrayHasKey('mistral-small', $opts);
    }

    public function test_model_options_without_placeholder_omits_empty_key(): void
    {
        $catalog = new EngineCatalog([]);
        $opts = $catalog->modelOptions('copilot', withPlaceholder: false);

        $this->assertArrayNotHasKey('', $opts);
        $this->assertArrayHasKey('claude-sonnet-4.6', $opts);
    }

    public function test_model_aliases_returns_sequential_id_name_pairs(): void
    {
        $catalog = new EngineCatalog([]);
        $aliases = $catalog->modelAliases('copilot');

        $this->assertIsList($aliases);
        $this->assertArrayHasKey('id', $aliases[0]);
        $this->assertArrayHasKey('name', $aliases[0]);
        $ids = array_column($aliases, 'id');
        $this->assertContains('claude-sonnet-4.6', $ids);
    }

    public function test_model_options_unknown_engine_returns_placeholder_only(): void
    {
        $catalog = new EngineCatalog([]);
        $opts = $catalog->modelOptions('nope');

        $this->assertSame(['' => '— 继承默认 —'], $opts);
    }
}
