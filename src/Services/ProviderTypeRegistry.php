<?php

namespace SuperAICore\Services;

use SuperAICore\Models\AiProvider;
use SuperAICore\Support\ProviderTypeDescriptor;

/**
 * Single source of truth for provider-type metadata.
 *
 * Resolution order (highest wins):
 *   1. Host config — `super-ai-core.provider_types` partial overrides (label
 *      key, icon, field list, env keys, …). Hosts can also add brand-new
 *      types the bundle doesn't know about.
 *   2. Bundled defaults — the 9 types SuperAICore ships: builtin, anthropic,
 *      anthropic-proxy, bedrock, vertex, openai, openai-compatible,
 *      google-ai, kiro-api.
 *
 * Consumers stop mirroring a parallel matrix:
 *   - `/providers` UI iterates `all()` or `forBackend($backend)`.
 *   - Backends inject env vars through `ProviderEnvBuilder` (reads envKey
 *     / baseUrlEnv / envExtras off the descriptor).
 *   - `AiProvider::requiresApiKey()` / `typesForBackend()` delegate here.
 *
 * When a new API type is added to SuperAICore (or via host config), every
 * call site picks it up automatically — no more shotgun edits across
 * `IntegrationController::PROVIDER_TYPES`, `ClaudeRunner::providerEnvVars()`,
 * or each backend's `buildEnv()`.
 */
class ProviderTypeRegistry
{
    /** @var array<string,ProviderTypeDescriptor>|null */
    private ?array $cache = null;

    public function __construct(private readonly array $configOverrides = []) {}

    /**
     * Every known type, keyed by id. Host overrides already merged.
     *
     * @return array<string,ProviderTypeDescriptor>
     */
    public function all(): array
    {
        if ($this->cache !== null) return $this->cache;

        $bundled = self::bundled();
        $overrides = $this->configOverrides;

        $out = [];
        foreach ($bundled as $type => $desc) {
            $out[$type] = isset($overrides[$type])
                ? $desc->mergedWith((array) $overrides[$type])
                : $desc;
        }
        // Host-only types (not in the bundle) are created from scratch.
        foreach ($overrides as $type => $data) {
            if (isset($out[$type])) continue;
            $out[$type] = ProviderTypeDescriptor::fromArray((string) $type, (array) $data);
        }

        return $this->cache = $out;
    }

    public function get(string $type): ?ProviderTypeDescriptor
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * Every descriptor whose `allowedBackends` contains $backend. Used by
     * the provider-creation modal to narrow the type dropdown.
     *
     * @return array<string,ProviderTypeDescriptor>
     */
    public function forBackend(string $backend): array
    {
        return array_filter(
            $this->all(),
            fn (ProviderTypeDescriptor $d) => in_array($backend, $d->allowedBackends, true),
        );
    }

    /** Convenience: does this type carry an api_key? */
    public function requiresApiKey(string $type): bool
    {
        return $this->get($type)?->needsApiKey ?? false;
    }

    /** Convenience: does this type require base_url? */
    public function requiresBaseUrl(string $type): bool
    {
        return $this->get($type)?->needsBaseUrl ?? false;
    }

    /**
     * The 9 bundled descriptors. Kept deliberately verbose — each row is
     * one conceptual API and touching one row should be localized.
     *
     * @return array<string,ProviderTypeDescriptor>
     */
    public static function bundled(): array
    {
        $rows = [
            AiProvider::TYPE_BUILTIN => [
                'icon'            => 'bi-box-arrow-in-right',
                'fields'          => [],
                'default_backend' => AiProvider::BACKEND_CLAUDE,
                'allowed_backends' => [
                    AiProvider::BACKEND_CLAUDE,
                    AiProvider::BACKEND_CODEX,
                    AiProvider::BACKEND_GEMINI,
                    AiProvider::BACKEND_COPILOT,
                    AiProvider::BACKEND_KIRO,
                ],
                'needs_api_key'  => false,
                'needs_base_url' => false,
                // builtin relies on the CLI's own keychain/session — no env injection.
            ],

            AiProvider::TYPE_ANTHROPIC => [
                'icon'             => 'bi-key',
                'fields'           => ['api_key'],
                'default_backend'  => AiProvider::BACKEND_SUPERAGENT,
                'allowed_backends' => [AiProvider::BACKEND_SUPERAGENT, AiProvider::BACKEND_CLAUDE],
                'env_key'          => 'ANTHROPIC_API_KEY',
            ],

            AiProvider::TYPE_ANTHROPIC_PROXY => [
                'icon'             => 'bi-globe',
                'fields'           => ['base_url', 'api_key'],
                'default_backend'  => AiProvider::BACKEND_SUPERAGENT,
                'allowed_backends' => [AiProvider::BACKEND_SUPERAGENT, AiProvider::BACKEND_CLAUDE],
                'env_key'          => 'ANTHROPIC_API_KEY',
                'base_url_env'     => 'ANTHROPIC_BASE_URL',
            ],

            AiProvider::TYPE_BEDROCK => [
                'icon'             => 'bi-cloud',
                'fields'           => ['region', 'access_key_id', 'secret_access_key'],
                'default_backend'  => AiProvider::BACKEND_CLAUDE,
                // Canonical matrix: Bedrock routes through Claude Code CLI only.
                // SuperTeam's historical PROVIDER_TYPES also listed superagent
                // here — hosts that want that back can override via
                // `super-ai-core.provider_types.bedrock.allowed_backends`.
                'allowed_backends' => [AiProvider::BACKEND_CLAUDE],
                'needs_api_key'    => false,
                'env_extras' => [
                    'AWS_ACCESS_KEY_ID'     => 'access_key_id',
                    'AWS_SECRET_ACCESS_KEY' => 'secret_access_key',
                    'AWS_REGION'            => 'region',
                ],
                // Claude CLI needs a sentinel env to switch into Bedrock mode.
                'backend_env_flags' => [
                    AiProvider::BACKEND_CLAUDE => ['CLAUDE_CODE_USE_BEDROCK' => '1'],
                ],
            ],

            AiProvider::TYPE_VERTEX => [
                'icon'             => 'bi-cloud',
                'fields'           => ['project_id', 'region'],
                'default_backend'  => AiProvider::BACKEND_CLAUDE,
                'allowed_backends' => [AiProvider::BACKEND_CLAUDE, AiProvider::BACKEND_GEMINI],
                'needs_api_key'    => false,
                'env_extras' => [
                    'GOOGLE_CLOUD_PROJECT'  => 'project_id',
                    'GOOGLE_CLOUD_LOCATION' => 'region',
                ],
                'backend_env_flags' => [
                    AiProvider::BACKEND_CLAUDE => ['CLAUDE_CODE_USE_VERTEX' => '1'],
                ],
            ],

            AiProvider::TYPE_GOOGLE_AI => [
                'icon'             => 'bi-google',
                'fields'           => ['api_key'],
                'default_backend'  => AiProvider::BACKEND_GEMINI,
                'allowed_backends' => [AiProvider::BACKEND_GEMINI],
                'env_key'          => 'GEMINI_API_KEY',
                // Gemini CLI accepts GOOGLE_API_KEY as a fallback; ProviderEnvBuilder
                // sets it alongside GEMINI_API_KEY when the caller doesn't override.
                'env_extras'       => ['GOOGLE_API_KEY' => 'api_key'],
            ],

            AiProvider::TYPE_OPENAI => [
                'icon'             => 'bi-stars',
                'fields'           => ['api_key'],
                'default_backend'  => AiProvider::BACKEND_SUPERAGENT,
                'allowed_backends' => [AiProvider::BACKEND_SUPERAGENT, AiProvider::BACKEND_CODEX],
                'env_key'          => 'OPENAI_API_KEY',
            ],

            AiProvider::TYPE_OPENAI_COMPATIBLE => [
                'icon'             => 'bi-plug',
                'fields'           => ['base_url', 'api_key'],
                'default_backend'  => AiProvider::BACKEND_SUPERAGENT,
                'allowed_backends' => [AiProvider::BACKEND_SUPERAGENT, AiProvider::BACKEND_CODEX],
                'env_key'          => 'OPENAI_API_KEY',
                'base_url_env'     => 'OPENAI_BASE_URL',
            ],

            AiProvider::TYPE_KIRO_API => [
                'icon'             => 'bi-magic',
                'fields'           => ['api_key'],
                'default_backend'  => AiProvider::BACKEND_KIRO,
                'allowed_backends' => [AiProvider::BACKEND_KIRO],
                'env_key'          => 'KIRO_API_KEY',
            ],
        ];

        $out = [];
        foreach ($rows as $type => $data) {
            $out[$type] = ProviderTypeDescriptor::fromArray($type, $data);
        }
        return $out;
    }
}
