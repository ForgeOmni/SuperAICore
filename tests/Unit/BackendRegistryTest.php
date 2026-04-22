<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Services\BackendRegistry;
use SuperAICore\Support\SuperAgentDetector;
use SuperAICore\Tests\TestCase;

class BackendRegistryTest extends TestCase
{
    public function test_registers_all_backends_by_default(): void
    {
        $registry = new BackendRegistry(null, [
            'claude_cli' => ['enabled' => true, 'binary' => 'claude', 'timeout' => 300],
            'codex_cli' => ['enabled' => true, 'binary' => 'codex', 'timeout' => 300],
            'gemini_cli' => ['enabled' => true, 'binary' => 'gemini', 'timeout' => 300],
            'copilot_cli' => ['enabled' => true, 'binary' => 'copilot', 'timeout' => 300, 'allow_all_tools' => true],
            'kiro_cli' => ['enabled' => true, 'binary' => 'kiro-cli', 'timeout' => 300, 'trust_all_tools' => true],
            'gemini_api' => ['enabled' => true],
            'superagent' => ['enabled' => true],
            'anthropic_api' => ['enabled' => true],
            'openai_api' => ['enabled' => true],
        ]);

        $names = $registry->names();
        $this->assertContains('anthropic_api', $names);
        $this->assertContains('openai_api', $names);
        $this->assertContains('claude_cli', $names);
        $this->assertContains('codex_cli', $names);
        $this->assertContains('gemini_cli', $names);
        $this->assertContains('copilot_cli', $names);
        $this->assertContains('kiro_cli', $names);
        $this->assertContains('gemini_api', $names);

        // SuperAgent registration depends on the SDK actually being present
        if (SuperAgentDetector::isAvailable()) {
            $this->assertContains('superagent', $names);
        } else {
            $this->assertNotContains('superagent', $names);
        }
    }

    public function test_disabled_backend_is_skipped(): void
    {
        $registry = new BackendRegistry(null, [
            'claude_cli' => ['enabled' => false],
            'codex_cli' => ['enabled' => false],
            'gemini_cli' => ['enabled' => false],
            'copilot_cli' => ['enabled' => false],
            'kiro_cli' => ['enabled' => false],
            'kimi_cli' => ['enabled' => false],
            'gemini_api' => ['enabled' => false],
            'superagent' => ['enabled' => false],
            'anthropic_api' => ['enabled' => true],
            'openai_api' => ['enabled' => false],
        ]);

        $this->assertSame(['anthropic_api'], $registry->names());
    }

    public function test_superagent_is_hidden_when_sdk_missing_even_with_config_enabled(): void
    {
        // Inject a "SDK missing" detector — composer requires the SDK as a
        // hard dep so class_exists is always true in the test matrix, but
        // the constructor still honours a callable that reports unavailable.
        $registry = new BackendRegistry(
            null,
            ['superagent' => ['enabled' => true]],
            fn() => false,
        );

        $this->assertNotContains('superagent', $registry->names());
    }

    public function test_superagent_registered_when_sdk_available_and_enabled(): void
    {
        $registry = new BackendRegistry(
            null,
            ['superagent' => ['enabled' => true]],
            fn() => true,
        );

        $this->assertContains('superagent', $registry->names());
    }

    public function test_get_returns_null_for_unknown_backend(): void
    {
        $registry = new BackendRegistry(null, []);
        $this->assertNull($registry->get('does-not-exist'));
    }
}
