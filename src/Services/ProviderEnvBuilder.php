<?php

namespace SuperAICore\Services;

use SuperAICore\Models\AiProvider;

/**
 * Centralized env-var injection for AiProvider rows.
 *
 * Before 0.6.2 the injection logic was duplicated:
 *   - `*CliBackend::buildEnv()` in each backend class (SuperAICore)
 *   - `ClaudeRunner::providerEnvVars()` (SuperTeam — big 7-case switch)
 *
 * Now both call sites ask this service. The descriptor in
 * `ProviderTypeRegistry` carries the env-key map, so adding a new type is
 * a one-row addition to the bundled registry and every consumer picks it
 * up with zero code changes.
 *
 * Behaviour:
 *   - `envKey`      — if the descriptor declares one AND the provider has an
 *                     api_key, the key is exported under that name. Caller
 *                     may override with `$apiKeyEnvKey` (some CLIs historically
 *                     reserve a different env name).
 *   - `baseUrlEnv`  — if declared and provider.base_url is set, exported
 *                     without trailing slash.
 *   - `envExtras`   — {env_name => extra_config_key} map. For each row,
 *                     if `extra_config[$key]` is non-empty, it's exported
 *                     under `$env_name`.
 *   - `backendEnvFlags[$backend]` — static flags like
 *                     `CLAUDE_CODE_USE_BEDROCK=1`, set only when the provider
 *                     is routed through a matching backend.
 *
 * The special-case for `google-ai` (Gemini CLI reads either GEMINI_API_KEY
 * or GOOGLE_API_KEY — we set both for compatibility) is expressed via
 * envExtras in the registry, not hardcoded here.
 */
class ProviderEnvBuilder
{
    public function __construct(private readonly ProviderTypeRegistry $registry) {}

    /**
     * @param AiProvider $provider       Persisted row whose type/credentials
     *                                   drive the injection.
     * @param string|null $apiKeyEnvKey  Override for the api_key env name
     *                                   (legacy — most callers should pass
     *                                   null and let the descriptor decide).
     *
     * @return array<string,string>  flat env map, ready for Process::setEnv()
     */
    public function buildEnv(AiProvider $provider, ?string $apiKeyEnvKey = null): array
    {
        $descriptor = $this->registry->get($provider->type);
        if ($descriptor === null) return [];

        $env = [];
        $apiKey = $provider->decrypted_api_key;
        $baseUrl = $provider->base_url ? rtrim((string) $provider->base_url, '/') : null;
        $extra = is_array($provider->extra_config) ? $provider->extra_config : [];

        if ($descriptor->envKey && $apiKey) {
            $env[$apiKeyEnvKey ?: $descriptor->envKey] = $apiKey;
        }

        if ($descriptor->baseUrlEnv && $baseUrl) {
            $env[$descriptor->baseUrlEnv] = $baseUrl;
        }

        foreach ($descriptor->envExtras as $envName => $extraKey) {
            // extra_config keys carry region/project_id/etc for bedrock/vertex.
            // google-ai abuses this to map GOOGLE_API_KEY → api_key (since
            // Gemini CLI accepts either env name).
            $value = null;
            if ($extraKey === 'api_key') {
                $value = $apiKey;
            } elseif (isset($extra[$extraKey]) && $extra[$extraKey] !== '') {
                $value = (string) $extra[$extraKey];
            }
            if ($value !== null && $value !== '') {
                $env[$envName] = $value;
            }
        }

        $flags = $descriptor->backendEnvFlags[$provider->backend] ?? [];
        foreach ($flags as $k => $v) {
            $env[(string) $k] = (string) $v;
        }

        return $env;
    }

    /**
     * Lower-level form: build env from a raw provider_config array (as
     * passed to `Dispatcher::dispatch(provider_config: …)`). Backend
     * classes that don't have an `AiProvider` model in hand go through
     * this path — `KiroCliBackend::buildEnv()` is the canonical example.
     *
     * @param array<string,mixed> $providerConfig
     * @return array<string,string>
     */
    public function buildEnvFromConfig(array $providerConfig): array
    {
        $type = $providerConfig['type'] ?? null;
        if (!is_string($type)) return [];

        $descriptor = $this->registry->get($type);
        if ($descriptor === null) return [];

        $env = [];
        $apiKey = $providerConfig['api_key'] ?? null;
        $baseUrl = isset($providerConfig['base_url']) && $providerConfig['base_url'] !== ''
            ? rtrim((string) $providerConfig['base_url'], '/')
            : null;
        $extra = is_array($providerConfig['extra_config'] ?? null) ? $providerConfig['extra_config'] : [];

        if ($descriptor->envKey && !empty($apiKey)) {
            $env[$descriptor->envKey] = (string) $apiKey;
        }
        if ($descriptor->baseUrlEnv && $baseUrl) {
            $env[$descriptor->baseUrlEnv] = $baseUrl;
        }
        foreach ($descriptor->envExtras as $envName => $extraKey) {
            $value = null;
            if ($extraKey === 'api_key') {
                $value = $apiKey;
            } elseif (!empty($extra[$extraKey])) {
                $value = (string) $extra[$extraKey];
            }
            if ($value !== null && $value !== '') {
                $env[$envName] = (string) $value;
            }
        }
        $backend = (string) ($providerConfig['backend'] ?? '');
        $flags = $descriptor->backendEnvFlags[$backend] ?? [];
        foreach ($flags as $k => $v) {
            $env[(string) $k] = (string) $v;
        }
        return $env;
    }
}
