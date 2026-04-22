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
 *     openai / gemini AI Studio / kimi / qwen / glm / minimax / openrouter)
 *
 * Reads API keys from the current process environment — matching SDK
 * behaviour. Callers that load keys from AiProvider rows should putenv()
 * them before calling `check()` (or pass them via a short-lived env
 * override). Does not hit the network unless a key is present.
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
     * @var list<string>
     */
    public const DEFAULT_PROVIDERS = [
        'anthropic',
        'openai',
        'openrouter',
        'gemini',
        'kimi',
        'qwen',
        'glm',
        'minimax',
    ];

    /**
     * Env var that carries the API key for each provider, used by
     * `filterToConfigured()` to prune providers the host hasn't set up.
     *
     * @var array<string,string>
     */
    private const ENV_KEY = [
        'anthropic'  => 'ANTHROPIC_API_KEY',
        'openai'     => 'OPENAI_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
        'gemini'     => 'GEMINI_API_KEY',
        'kimi'       => 'KIMI_API_KEY',
        'qwen'       => 'QWEN_API_KEY',
        'glm'        => 'GLM_API_KEY',
        'minimax'    => 'MINIMAX_API_KEY',
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
     * Keep only providers whose API-key env var is set. Lets the default
     * `api:status` invocation avoid emitting "no API key" rows for every
     * provider the host doesn't use.
     *
     * @param  list<string> $providers
     * @return list<string>
     */
    public static function filterToConfigured(array $providers): array
    {
        return array_values(array_filter($providers, static function (string $p): bool {
            $envName = self::ENV_KEY[$p] ?? null;
            if ($envName === null) return false;
            $val = $_ENV[$envName] ?? getenv($envName);
            return $val !== false && $val !== '';
        }));
    }
}
