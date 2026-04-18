<?php

namespace SuperAICore\Support;

use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Services\EngineCatalog;

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
 * Dispatcher-backend → engine mapping is owned by EngineCatalog. The constant
 * below is kept as a fallback when the container isn't booted (e.g. low-level
 * unit tests instantiating BackendRegistry directly), but the live UI/runtime
 * paths always resolve through the catalog so adding a new engine in
 * EngineCatalog::seed() doesn't require editing this file.
 */
class BackendState
{
    /** Static fallback when EngineCatalog isn't available (e.g. bare unit tests). */
    const DISPATCHER_TO_ENGINE = [
        'claude_cli'    => 'claude',
        'anthropic_api' => 'claude',
        'codex_cli'     => 'codex',
        'openai_api'    => 'codex',
        'gemini_cli'    => 'gemini',
        'gemini_api'    => 'gemini',
        'copilot_cli'   => 'copilot',
        'superagent'    => 'superagent',
    ];

    public static function isEngineDisabled(string $engine): bool
    {
        return IntegrationConfig::getValue('ai_execution', 'backend_disabled.' . $engine) === '1';
    }

    public static function isDispatcherBackendAllowed(string $backendName): bool
    {
        $map = self::dispatcherToEngineMap();
        $engine = $map[$backendName] ?? null;
        if ($engine === null) {
            return true;
        }
        return !self::isEngineDisabled($engine);
    }

    /**
     * Resolve the live mapping. Prefers the EngineCatalog (so config-defined
     * engines / new CLIs are picked up) and falls back to the constant.
     *
     * @return array<string,string>
     */
    public static function dispatcherToEngineMap(): array
    {
        if (function_exists('app')) {
            try {
                return app(EngineCatalog::class)->dispatcherToEngineMap();
            } catch (\Throwable $e) {
                // container not booted — fall through to constant
            }
        }
        return self::DISPATCHER_TO_ENGINE;
    }
}
