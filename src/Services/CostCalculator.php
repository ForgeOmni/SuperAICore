<?php

namespace SuperAICore\Services;

class CostCalculator
{
    public const BILLING_USAGE        = 'usage';
    public const BILLING_SUBSCRIPTION = 'subscription';

    /** @var array<string,array{input:float,output:float,billing_model?:string}> */
    protected array $pricing;

    public function __construct(array $pricing = [])
    {
        $this->pricing = $pricing ?: (function_exists('config') ? (config('super-ai-core.model_pricing') ?? []) : []);
    }

    /**
     * Compute USD cost. Subscription-billed models always return 0 — the
     * dashboard surfaces them in a separate "subscription engines" panel.
     */
    public function calculate(string $model, int $inputTokens, int $outputTokens, ?string $backend = null): float
    {
        $rate = $this->resolveRate($model, $backend);
        if (!$rate) return 0.0;

        if (($rate['billing_model'] ?? self::BILLING_USAGE) === self::BILLING_SUBSCRIPTION) {
            return 0.0;
        }

        $input  = ($inputTokens  / 1_000_000) * (float) ($rate['input']  ?? 0);
        $output = ($outputTokens / 1_000_000) * (float) ($rate['output'] ?? 0);
        return round($input + $output, 6);
    }

    /**
     * Returns the full pricing entry (or null when unknown). Useful for
     * dashboards that want to render the unit rate next to a row.
     */
    public function pricingFor(string $model, ?string $backend = null): ?array
    {
        return $this->resolveRate($model, $backend);
    }

    /**
     * Returns 'usage' | 'subscription' for the given model. Falls back to
     * the engine's billing model from EngineCatalog when the pricing entry
     * is missing — so a Copilot-routed model still reports 'subscription'
     * even if it isn't enumerated in `model_pricing`.
     */
    public function billingModel(string $model, ?string $backend = null): string
    {
        $rate = $this->resolveRate($model, $backend);
        if ($rate && isset($rate['billing_model'])) {
            return (string) $rate['billing_model'];
        }
        if ($backend && function_exists('app')) {
            try {
                $engineKey = $this->backendToEngine($backend);
                $engine = $engineKey ? app(EngineCatalog::class)->get($engineKey) : null;
                if ($engine) {
                    return $engine->billingModel;
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return self::BILLING_USAGE;
    }

    /**
     * Lookup pricing using a deterministic strategy:
     *
     *   1. Backend-prefixed key (e.g. "copilot:claude-sonnet-4-5")
     *   2. Exact model id
     *   3. Longest-prefix match against any pricing key
     *
     * This avoids the previous bug where `gpt-5` matched `gpt-4o` because
     * both started with "gpt".
     *
     * @return array{input:float,output:float,billing_model?:string}|null
     */
    private function resolveRate(string $model, ?string $backend): ?array
    {
        if ($backend) {
            $engineKey = $this->backendToEngine($backend);
            if ($engineKey) {
                $prefixed = "{$engineKey}:{$model}";
                if (isset($this->pricing[$prefixed])) {
                    return $this->pricing[$prefixed];
                }
            }
        }

        if (isset($this->pricing[$model])) {
            return $this->pricing[$model];
        }

        // Longest-prefix match (e.g. "claude-sonnet-4-5-20241022" → "claude-sonnet-4-5")
        $best = null;
        $bestLen = 0;
        foreach ($this->pricing as $key => $val) {
            if (str_contains($key, ':')) continue; // skip backend-prefixed keys in fallback
            if (str_starts_with($model, $key) && strlen($key) > $bestLen) {
                $best = $val;
                $bestLen = strlen($key);
            }
        }
        return $best;
    }

    private function backendToEngine(string $backend): ?string
    {
        // Caller may pass either an engine key ('copilot') or a dispatcher
        // backend ('copilot_cli'); normalise.
        if (function_exists('app')) {
            try {
                $map = app(EngineCatalog::class)->dispatcherToEngineMap();
                if (isset($map[$backend])) {
                    return $map[$backend];
                }
            } catch (\Throwable $e) {
                // fall through to literal
            }
        }
        return $backend;
    }
}
