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
     * @param ?ProcessSpec $processSpec      declarative command-shape for CLI engines (null for non-CLI)
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
        public readonly ?ProcessSpec $processSpec = null,
        /**
         * False when the CLI has no reliable login-status probe a host
         * can run without blocking or producing false negatives (gemini:
         * `gemini login` is interactive and has no `--status`
         * counterpart; oauth may live in env / gcloud ADC). Hosts that
         * gate builtin execution on `auth.loggedIn` should skip the
         * check for these engines and surface auth failures in the run
         * log instead.
         *
         * Default true — engines assert they have a reliable probe unless
         * their descriptor sets this to false.
         */
        public readonly bool $authProbeReliable = true,
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
            'process_spec'        => $this->processSpec?->toArray(),
            'has_builtin_auth'    => $this->hasBuiltinAuth(),
            'auth_probe_reliable' => $this->authProbeReliable,
        ];
    }

    /**
     * True when this engine can run without an AiProvider row — i.e.
     * at least one of its allowed `provider_types` doesn't need an API
     * key (builtin OAuth: `claude`'s `builtin` / `kimi`'s `moonshot-builtin`
     * / `copilot`'s `builtin` / etc.).
     *
     * Hosts use this to decide whether to render a "Built-in (<engine>)"
     * execution-target row: engines without a builtin auth channel
     * (e.g. `superagent`) always require a user-configured provider.
     *
     * Data-driven via the SuperAICore ProviderTypeRegistry — new engines
     * that declare a `needs_api_key: false` variant type become "builtin-
     * capable" without any host code change.
     */
    public function hasBuiltinAuth(): bool
    {
        if (!$this->providerTypes) return false;
        if (!function_exists('app')) return false;

        try {
            $registry = app(\SuperAICore\Services\ProviderTypeRegistry::class);
        } catch (\Throwable) {
            return false;
        }

        foreach ($this->providerTypes as $typeKey) {
            $desc = $registry->get((string) $typeKey);
            if ($desc && $desc->needsApiKey === false) {
                return true;
            }
        }
        return false;
    }
}
