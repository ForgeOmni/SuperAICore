<?php

namespace SuperAICore\Support;

/**
 * Read-only metadata bundle for a single execution engine.
 *
 * "Engine" is the user-facing concept (one of: claude, codex, gemini, copilot,
 * superagent). Each engine fans out to one or more Dispatcher backends
 * (e.g. claude → claude_cli + anthropic_api). Host apps and Blade templates
 * read engines from EngineCatalog instead of hardcoding lists, so adding a
 * new CLI here doesn't require a host-app patch.
 *
 * @phpstan-type EngineKey 'claude'|'codex'|'gemini'|'copilot'|'superagent'|string
 */
final class EngineDescriptor
{
    /**
     * @param string   $key                  short engine id (matches AiProvider::BACKEND_*)
     * @param string   $label                user-facing name shown in the UI
     * @param string   $icon                 Bootstrap-icons class fragment (no `bi-` prefix)
     * @param string[] $dispatcherBackends   names registered in BackendRegistry (e.g. ['claude_cli', 'anthropic_api'])
     * @param string[] $providerTypes        AiProvider::TYPE_* values valid for this engine
     * @param string[] $availableModels      model IDs the engine can route (empty = engine-default only)
     * @param bool     $isCli                whether this engine is fronted by a local CLI binary
     * @param ?string  $cliBinary            binary name (when isCli=true)
     * @param ?string  $defaultModel         convenience pick when caller doesn't specify one
     * @param string   $billingModel         'usage' (per-token, USD) | 'subscription' (flat fee, e.g. Copilot)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $icon,
        public readonly array  $dispatcherBackends,
        public readonly array  $providerTypes,
        public readonly array  $availableModels,
        public readonly bool   $isCli,
        public readonly ?string $cliBinary = null,
        public readonly ?string $defaultModel = null,
        public readonly string $billingModel = 'usage',
    ) {}

    public function toArray(): array
    {
        return [
            'key'                 => $this->key,
            'label'               => $this->label,
            'icon'                => $this->icon,
            'dispatcher_backends' => $this->dispatcherBackends,
            'provider_types'      => $this->providerTypes,
            'available_models'    => $this->availableModels,
            'is_cli'              => $this->isCli,
            'cli_binary'          => $this->cliBinary,
            'default_model'       => $this->defaultModel,
            'billing_model'       => $this->billingModel,
        ];
    }
}
