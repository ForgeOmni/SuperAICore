<?php

namespace SuperAICore\Services;

use SuperAgent\Providers\ProviderRegistry;

/**
 * Lightweight API-provider reachability probe. Wraps SuperAgent SDK's
 * `ProviderRegistry::healthCheck()` (a 5s cURL hit against each provider's
 * cheapest listing endpoint) and normalises the shape so callers can
 * treat `latency_ms` and `reason` as always-present keys.
 *
 * Sibling of `CliStatusDetector`:
 *   - `CliStatusDetector` reports on engine CLIs (claude / codex / gemini / …)
 *   - `ApiHealthDetector` reports on direct-HTTP API providers (anthropic /
 *     openai / gemini AI Studio / kimi / qwen / qwen-native / glm / minimax /
 *     openrouter)
 *
 * Reads API keys from the current process environment — matching SDK
 * behaviour. Callers that load keys from AiProvider rows should putenv()
 * them before calling `check()` (or pass them via a short-lived env
 * override). Does not hit the network unless a key is present.
 *
 * 0.9.0 note — Qwen has two provider keys with the same credentials:
 *   - `qwen`        — OpenAI-compat (`/compatible-mode/v1/chat/completions`),
 *                     default binding since SDK 0.9.0. This is what
 *                     Alibaba's own qwen-code uses in production.
 *   - `qwen-native` — legacy DashScope-native body shape
 *                     (`text-generation/generation`). Keep using this when
 *                     you need `parameters.thinking_budget` or
 *                     `parameters.enable_code_interpreter`.
 * Both read the same `QWEN_API_KEY` / `DASHSCOPE_API_KEY` env, so any host
 * that already has Qwen configured picks up `qwen-native` for free.
 *
 * Kimi / Qwen Code OAuth: when a host has logged in with
 * `superagent auth login kimi-code` or `… qwen-code` the credential files
 * under `~/.superagent/credentials/` count as "configured" even without
 * an API-key env var. `filterToConfigured()` honours both.
 */
final class ApiHealthDetector
{
    /**
     * Default set — the providers we consider first-class in this package.
     * `bedrock` is omitted because it routes through the AWS SDK (no plain
     * HTTP probe), and `ollama` is a local daemon the operator owns — both
     * stay available in the SDK but we don't surface them in the default
     * dashboard probe.
     *
     * `qwen-native` is included alongside `qwen` so hosts that still rely
     * on the DashScope-native body shape (for code_interpreter or
     * thinking_budget) see both endpoints in the dashboard probe.
     *
     * @var list<string>
     */
    public const DEFAULT_PROVIDERS = [
        'anthropic',
        'openai',
        'openrouter',
        'gemini',
        'kimi',
        'qwen',
        'qwen-native',
        'glm',
        'minimax',
    ];

    /**
     * Env var that carries the API key for each provider, used by
     * `filterToConfigured()` to prune providers the host hasn't set up.
     * Qwen's two registry keys share `QWEN_API_KEY`.
     *
     * @var array<string,string>
     */
    private const ENV_KEY = [
        'anthropic'   => 'ANTHROPIC_API_KEY',
        'openai'      => 'OPENAI_API_KEY',
        'openrouter'  => 'OPENROUTER_API_KEY',
        'gemini'      => 'GEMINI_API_KEY',
        'kimi'        => 'KIMI_API_KEY',
        'qwen'        => 'QWEN_API_KEY',
        'qwen-native' => 'QWEN_API_KEY',
        'glm'         => 'GLM_API_KEY',
        'minimax'     => 'MINIMAX_API_KEY',
    ];

    /**
     * Providers that accept an OAuth bearer in place of an API key. A
     * credential file at `~/.superagent/credentials/<key>.json` counts as
     * "configured" for `filterToConfigured()` even when the matching
     * API-key env var is unset.
     *
     * @var array<string,string>
     */
    private const OAUTH_CREDENTIAL = [
        'kimi' => 'kimi-code',
        'qwen' => 'qwen-code',
    ];

    /**
     * Probe a single provider.
     *
     * @return array{provider:string,ok:bool,latency_ms:?int,reason:?string}
     */
    public static function check(string $provider): array
    {
        if (!class_exists(ProviderRegistry::class)) {
            return [
                'provider'   => $provider,
                'ok'         => false,
                'latency_ms' => null,
                'reason'     => 'SuperAgent SDK not installed',
            ];
        }

        try {
            $raw = ProviderRegistry::healthCheck($provider);
        } catch (\Throwable $e) {
            return [
                'provider'   => $provider,
                'ok'         => false,
                'latency_ms' => null,
                'reason'     => 'probe threw: ' . $e->getMessage(),
            ];
        }

        return [
            'provider'   => (string) ($raw['provider'] ?? $provider),
            'ok'         => (bool)   ($raw['ok']       ?? false),
            'latency_ms' => isset($raw['latency_ms']) ? (int) $raw['latency_ms'] : null,
            'reason'     => isset($raw['reason']) ? (string) $raw['reason'] : null,
        ];
    }

    /**
     * Probe a list of providers.
     *
     * @param  list<string>|null $providers  null → DEFAULT_PROVIDERS filtered
     *                                       by `filterToConfigured()`
     * @return list<array{provider:string,ok:bool,latency_ms:?int,reason:?string}>
     */
    public static function checkMany(?array $providers = null): array
    {
        $providers ??= self::filterToConfigured(self::DEFAULT_PROVIDERS);
        $out = [];
        foreach ($providers as $p) {
            $out[] = self::check($p);
        }
        return $out;
    }

    /**
     * Keep only providers that are configured — either the API-key env var
     * is set OR the SDK 0.9.0 OAuth credential file is present
     * (`~/.superagent/credentials/<key>.json` after
     * `superagent auth login kimi-code` / `qwen-code`).
     *
     * Lets the default `api:status` invocation avoid emitting "no API key"
     * rows for every provider the host doesn't use, while still surfacing
     * providers the operator logged into interactively.
     *
     * @param  list<string> $providers
     * @return list<string>
     */
    public static function filterToConfigured(array $providers): array
    {
        return array_values(array_filter($providers, static function (string $p): bool {
            $envName = self::ENV_KEY[$p] ?? null;
            if ($envName !== null) {
                $val = $_ENV[$envName] ?? getenv($envName);
                if ($val !== false && $val !== '') {
                    return true;
                }
            }
            return self::hasOauthCredential($p);
        }));
    }

    /**
     * True iff `$provider` has an SDK 0.9.0 OAuth credential on disk —
     * `~/.superagent/credentials/<kimi-code|qwen-code>.json`. Checked by
     * path existence only (never read), so we don't depend on the SDK
     * credential classes being loadable.
     */
    private static function hasOauthCredential(string $provider): bool
    {
        $key = self::OAUTH_CREDENTIAL[$provider] ?? null;
        if ($key === null) return false;

        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');
        if (!is_string($home) || $home === '') return false;

        return is_file("{$home}/.superagent/credentials/{$key}.json");
    }
}
