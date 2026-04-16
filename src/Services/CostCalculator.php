<?php

namespace ForgeOmni\AiCore\Services;

class CostCalculator
{
    protected array $pricing;

    public function __construct(array $pricing = [])
    {
        $this->pricing = $pricing ?: (function_exists('config') ? (config('ai-core.model_pricing') ?? []) : []);
    }

    /**
     * @param  string $model
     * @param  int    $inputTokens
     * @param  int    $outputTokens
     * @return float  USD
     */
    public function calculate(string $model, int $inputTokens, int $outputTokens): float
    {
        $rate = $this->pricing[$model] ?? null;
        if (!$rate) {
            // Try prefix match (strip date suffix)
            foreach ($this->pricing as $key => $val) {
                if (str_starts_with($model, $key) || str_starts_with($key, explode('-', $model)[0])) {
                    $rate = $val;
                    break;
                }
            }
        }
        if (!$rate) return 0.0;

        $input = ($inputTokens / 1_000_000) * ($rate['input'] ?? 0);
        $output = ($outputTokens / 1_000_000) * ($rate['output'] ?? 0);
        return round($input + $output, 6);
    }

    public function pricingFor(string $model): ?array
    {
        return $this->pricing[$model] ?? null;
    }
}
