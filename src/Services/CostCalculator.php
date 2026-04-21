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
     *
     * Safety net: when the resolved rate lacks an explicit `billing_model`
     * (e.g. the host catalog has `gpt-5` as a usage-priced entry but hasn't
     * enumerated `copilot:gpt-5.X`), we consult the engine catalog for the
     * *backend*. If that engine is subscription-billed we still return 0
     * regardless of the prefix-matched rate, matching billingModel()'s
     * answer so a row's real cost never disagrees with its billing_model.
     */
    public function calculate(string $model, int $inputTokens, int $outputTokens, ?string $backend = null): float
    {
        if ($this->engineBillingModel($backend) === self::BILLING_SUBSCRIPTION) {
            return 0.0;
        }

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
     * Returns the engine-level billing model for $backend (via EngineCatalog),
     * or null when we can't determine it. Pulled out so calculate() and
     * billingModel() can share the catalog fallback.
     */
    private function engineBillingModel(?string $backend): ?string
    {
        if (!$backend || !function_exists('app')) return null;
        try {
            $engineKey = $this->backendToEngine($backend);
            if (!$engineKey) return null;
            $engine = app(EngineCatalog::class)->get($engineKey);
            return $engine ? $engine->billingModel : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * "Shadow" cost — the USD the same tokens would have cost on a
     * pay-as-you-go plan, regardless of the configured billing model.
     *
     * Use case: Copilot and Claude Code builtin bill by subscription
     * ($0 per call), but operators still want to see how much throughput
     * those sessions represent so they can compare engines fairly. We
     * resolve the rate from the usage-priced catalog entry for the same
     * model id (dropping the `copilot:` prefix that would pin it to
     * subscription), fall through to the SuperAgent ModelCatalog, and
     * compute a per-token estimate. Returns 0 when the rate is unknown
     * or when no tokens are supplied.
     */
    public function shadowCalculate(string $model, int $inputTokens, int $outputTokens): float
    {
        if ($inputTokens <= 0 && $outputTokens <= 0) return 0.0;

        $rate = $this->resolveUsagePricedRate($model);
        if (!$rate) return 0.0;

        $input  = ($inputTokens  / 1_000_000) * (float) ($rate['input']  ?? 0);
        $output = ($outputTokens / 1_000_000) * (float) ($rate['output'] ?? 0);
        return round($input + $output, 6);
    }

    /**
     * Resolve a usage-billed rate for $model — ignoring any backend prefix
     * that would otherwise route to a subscription entry. Shared between
     * shadowCalculate() and any caller that wants the raw pay-as-you-go
     * rates for display.
     *
     * @return array{input:float,output:float}|null
     */
    private function resolveUsagePricedRate(string $model): ?array
    {
        if (isset($this->pricing[$model])
            && (($this->pricing[$model]['billing_model'] ?? self::BILLING_USAGE) === self::BILLING_USAGE)) {
            return $this->pricing[$model];
        }

        $best = null;
        $bestLen = 0;
        foreach ($this->pricing as $key => $val) {
            if (str_contains($key, ':')) continue;
            if (($val['billing_model'] ?? self::BILLING_USAGE) !== self::BILLING_USAGE) continue;
            if (str_starts_with($model, $key) && strlen($key) > $bestLen) {
                $best = $val;
                $bestLen = strlen($key);
            }
        }
        if ($best !== null) return $best;

        return $this->pricingFromCatalog($model);
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
        $engineBilling = $this->engineBillingModel($backend);
        if ($engineBilling === self::BILLING_SUBSCRIPTION) {
            return self::BILLING_SUBSCRIPTION;
        }
        $rate = $this->resolveRate($model, $backend);
        if ($rate && isset($rate['billing_model'])) {
            return (string) $rate['billing_model'];
        }
        return $engineBilling ?? self::BILLING_USAGE;
    }

    /**
     * Lookup pricing using a deterministic strategy:
     *
     *   1. Backend-prefixed key (e.g. "copilot:claude-sonnet-4-5")
     *   2. Exact model id
     *   3. Longest-prefix match against any pricing key
     *   4. SuperAgent ModelCatalog (bundled resources/models.json + user
     *      override at ~/.superagent/models.json). Users who run
     *      `superagent models update` immediately get accurate pricing
     *      for every Anthropic/OpenAI/Gemini/Bedrock/OpenRouter row
     *      without republishing this config.
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
        if ($best !== null) {
            return $best;
        }

        return $this->pricingFromCatalog($model);
    }

    /**
     * Consult SuperAgent's ModelCatalog (bundled + user override) for a
     * model that isn't in the host's `model_pricing` config. Returns null
     * if the class isn't available, the model is unknown, or the entry
     * lacks input/output rates.
     *
     * @return array{input:float,output:float}|null
     */
    private function pricingFromCatalog(string $model): ?array
    {
        if (!class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            return null;
        }
        try {
            return \SuperAgent\Providers\ModelCatalog::pricing($model);
        } catch (\Throwable) {
            return null;
        }
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
