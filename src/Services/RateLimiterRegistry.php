<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Providers\Transport\TokenBucket;

/**
 * Per-process token-bucket pool keyed by provider name (or any caller-
 * chosen tag — usually the dispatch's `backend:model` pair when one
 * model is more rate-limited than another in the same provider's
 * account).
 *
 * Wired by `SuperAgentBackend::generate()` and `SquadBackend` before
 * each provider call. Hosts that drive their own dispatcher (custom
 * CLI backends, ad-hoc scripts) can also `consume()` to participate
 * in the same per-key budget.
 *
 * Configuration shape (`super-ai-core.rate_limits`):
 *
 *   'rate_limits' => [
 *       'default' => ['rate' => 8.0,  'burst' => 16],
 *       'kimi'    => ['rate' => 5.0,  'burst' => 10],
 *       'openai'  => ['rate' => 16.0, 'burst' => 32],
 *       'deepseek'=> ['rate' => 8.0,  'burst' => 16],   // matches DeepSeek-TUI
 *   ],
 *
 * When a key is missing the registry falls back to `default`. When
 * `default` is missing the registry returns null and `consume()` is a
 * no-op (rate limiting silently disabled). That keeps existing hosts
 * byte-compatible until they explicitly opt in via config.
 *
 * Per-process by design: distributed swarms (one agent per pod) need a
 * shared limiter — the cleanest path there is a Redis-backed Guzzle
 * middleware on the provider's HTTP client; this registry does NOT
 * compete with that and intentionally stays simple.
 */
final class RateLimiterRegistry
{
    /** @var array<string, TokenBucket> */
    private array $buckets = [];

    public function __construct(
        /** @var array<string, array{rate: float, burst: int}> */
        private array $config = [],
    ) {}

    /**
     * Block until the named bucket has capacity, then consume one
     * token. No-op when the SDK's TokenBucket class isn't on the
     * classpath (older SDK) or when no `default` config is wired.
     */
    public function consume(string $key, int $tokens = 1): void
    {
        $bucket = $this->bucketFor($key);
        if ($bucket === null) return;
        try {
            $bucket->consume($tokens);
        } catch (\Throwable) {
            // Limiter must never break a real dispatch — degrade silently.
        }
    }

    /**
     * Non-blocking variant. Returns true when capacity was available
     * (and was consumed); false otherwise so callers can choose to
     * queue, drop, or fall back.
     */
    public function tryConsume(string $key, int $tokens = 1): bool
    {
        $bucket = $this->bucketFor($key);
        if ($bucket === null) return true;
        try {
            return $bucket->tryConsume($tokens);
        } catch (\Throwable) {
            return true;
        }
    }

    public function bucketFor(string $key): ?TokenBucket
    {
        if (!class_exists(TokenBucket::class)) return null;
        if (isset($this->buckets[$key])) return $this->buckets[$key];

        $cfg = $this->config[$key] ?? $this->config['default'] ?? null;
        if ($cfg === null) return null;

        $rate  = (float) ($cfg['rate'] ?? 8.0);
        $burst = (int)   ($cfg['burst'] ?? 16);
        if ($rate <= 0.0) return null;

        return $this->buckets[$key] = new TokenBucket(
            ratePerSecond: $rate,
            burst:         max(1, $burst),
        );
    }
}
