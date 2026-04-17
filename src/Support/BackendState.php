<?php

namespace SuperAICore\Support;

use SuperAICore\Models\IntegrationConfig;

/**
 * Runtime enable/disable state for execution engines.
 *
 * The providers page (/super-ai-core/providers) exposes a per-engine toggle.
 * When an engine is disabled, every provider bound to it becomes unusable —
 * the Dispatcher refuses to route to it and the UI hides the "activate"
 * buttons.
 *
 * The toggle state is persisted in IntegrationConfig so it survives deploys
 * and is shared across workers without touching .env.
 *
 * The three UI engines (claude, codex, superagent) fan out to five Dispatcher
 * backends; the map below keeps that translation in one place.
 */
class BackendState
{
    /**
     * Map a Dispatcher backend name to the "engine" exposed on the UI.
     */
    const DISPATCHER_TO_ENGINE = [
        'claude_cli'    => 'claude',
        'anthropic_api' => 'claude',
        'codex_cli'     => 'codex',
        'openai_api'    => 'codex',
        'superagent'    => 'superagent',
    ];

    public static function isEngineDisabled(string $engine): bool
    {
        return IntegrationConfig::getValue('ai_execution', 'backend_disabled.' . $engine) === '1';
    }

    public static function isDispatcherBackendAllowed(string $backendName): bool
    {
        $engine = self::DISPATCHER_TO_ENGINE[$backendName] ?? null;
        if ($engine === null) {
            return true;
        }
        return !self::isEngineDisabled($engine);
    }
}
