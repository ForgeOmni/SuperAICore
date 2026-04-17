<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Support\BackendState;
use SuperAICore\Tests\TestCase;

class BackendStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_engine_is_enabled_by_default(): void
    {
        $this->assertFalse(BackendState::isEngineDisabled('claude'));
        $this->assertFalse(BackendState::isEngineDisabled('codex'));
        $this->assertFalse(BackendState::isEngineDisabled('gemini'));
        $this->assertFalse(BackendState::isEngineDisabled('superagent'));
    }

    public function test_disabling_gemini_blocks_gemini_cli_and_gemini_api(): void
    {
        IntegrationConfig::setValue('ai_execution', 'backend_disabled.gemini', '1');

        $this->assertFalse(BackendState::isDispatcherBackendAllowed('gemini_cli'));
        $this->assertFalse(BackendState::isDispatcherBackendAllowed('gemini_api'));
        // ... but leaves the other engines alone
        $this->assertTrue(BackendState::isDispatcherBackendAllowed('claude_cli'));
        $this->assertTrue(BackendState::isDispatcherBackendAllowed('codex_cli'));
    }

    public function test_disabling_an_engine_blocks_all_its_dispatcher_backends(): void
    {
        IntegrationConfig::setValue('ai_execution', 'backend_disabled.claude', '1');

        // "claude" engine blocks both claude_cli AND anthropic_api
        $this->assertFalse(BackendState::isDispatcherBackendAllowed('claude_cli'));
        $this->assertFalse(BackendState::isDispatcherBackendAllowed('anthropic_api'));
        // ... but leaves codex/openai alone
        $this->assertTrue(BackendState::isDispatcherBackendAllowed('codex_cli'));
        $this->assertTrue(BackendState::isDispatcherBackendAllowed('openai_api'));
    }

    public function test_disabling_codex_blocks_codex_cli_and_openai_api(): void
    {
        IntegrationConfig::setValue('ai_execution', 'backend_disabled.codex', '1');

        $this->assertFalse(BackendState::isDispatcherBackendAllowed('codex_cli'));
        $this->assertFalse(BackendState::isDispatcherBackendAllowed('openai_api'));
        $this->assertTrue(BackendState::isDispatcherBackendAllowed('claude_cli'));
    }

    public function test_unknown_dispatcher_backend_is_always_allowed(): void
    {
        // Not in DISPATCHER_TO_ENGINE map → no engine gate applies
        $this->assertTrue(BackendState::isDispatcherBackendAllowed('some-future-backend'));
    }

    public function test_re_enabling_restores_access(): void
    {
        IntegrationConfig::setValue('ai_execution', 'backend_disabled.claude', '1');
        $this->assertFalse(BackendState::isDispatcherBackendAllowed('claude_cli'));

        IntegrationConfig::setValue('ai_execution', 'backend_disabled.claude', '0');
        $this->assertTrue(BackendState::isDispatcherBackendAllowed('claude_cli'));
    }
}
