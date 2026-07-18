<?php

namespace SuperAICore\Services;

/**
 * Thin façade on top of UsageTracker + CostCalculator so callers only
 * need to supply {backend, model, input_tokens, output_tokens, task_type}
 * and we fill in:
 *
 *   - cost_usd         real billed amount ($0 for subscription engines)
 *   - shadow_cost_usd  pay-as-you-go estimate regardless of billing model
 *   - billing_model    'usage' | 'subscription'
 *
 * Shadow cost lets a Copilot/Claude-Code-builtin sessions appear on the
 * Cost Analytics dashboard with a meaningful number, so operators can
 * compare throughput across engines fairly without hand-maintaining a
 * second pricing source.
 *
 * Typical use (host AgentRunner):
 *   app(UsageRecorder::class)->record([
 *       'task_type'     => 'tasks.run',     // or 'ppt.strategist', 'ppt.executor', …
 *       'capability'    => 'agent_spawn',
 *       'backend'       => 'claude_cli',
 *       'model'         => 'claude-sonnet-4-5-20241022',
 *       'input_tokens'  => 12345,
 *       'output_tokens' => 6789,
 *       'duration_ms'   => 45000,
 *       'user_id'       => auth()->id(),
 *       'metadata'      => ['task_id' => 123, 'run_id' => 456],
 *   ]);
 *
 * Idempotency: not enforced here — callers should call once per CLI turn.
 */
class UsageRecorder
{
    public function __construct(
        protected ?UsageTracker $usage = null,
        protected ?CostCalculator $costs = null,
    ) {}

    /**
     * Record one CLI/API execution. Computes cost + shadow_cost + billing
     * model from the pricing catalog before forwarding to UsageTracker.
     *
     * Minimum required: backend, model. Everything else is optional —
     * task_type may be null, but callers are strongly encouraged to set
     * it so dashboards can bucket by feature.
     *
     * @param array{
     *   backend: string,
     *   model: string,
     *   task_type?: ?string,
     *   capability?: ?string,
     *   input_tokens?: int,
     *   output_tokens?: int,
     *   cache_read_tokens?: int,   Anthropic prompt-cache reads (~10% of input).
     *                              Also accepts the legacy `cache_hit_tokens`
     *                              alias — DeepSeek V3 / R1 wires emit that
     *                              key, and SDK 0.9.6 surfaces it under the
     *                              same shape; we accept the alias to avoid
     *                              a host-side translation layer.
     *   cache_write_tokens?: int,  Anthropic prompt-cache writes (~125% of input)
     *   duration_ms?: ?int,
     *   provider_id?: ?int,
     *   service_id?: ?int,
     *   user_id?: ?int,
     *   metadata?: ?array,
     *   cost_usd?: ?float,        override — skip calculator (authoritative
     *                              when the CLI reported total_cost_usd)
     *   shadow_cost_usd?: ?float, override — skip calculator
     *   idempotency_key?: ?string, when set, repository returns the id of an
     *                              existing row written within
     *                              IDEMPOTENCY_WINDOW_SECONDS (default 60)
     *                              instead of inserting a duplicate. Hosts
     *                              that double-record (Dispatcher + their
     *                              own UsageRecorder call for the same turn)
     *                              stop double-counting without a code change.
     * } $data
     */
    public function record(array $data): ?int
    {
        if (!$this->usage) return null;

        $backend = $data['backend'] ?? null;
        $model = $data['model'] ?? null;
        if (!$backend || !$model) return null;

        $inputTokens = (int) ($data['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['output_tokens'] ?? 0);
        // SDK 0.9.6 fix — `prompt_cache_hit_tokens` is the DeepSeek V3 / R1
        // historical wire; the SDK now recognises it natively. Accept either
        // name here so hosts that captured the raw provider envelope shape
        // (instead of going through the SDK's normalised Usage object) stop
        // silently dropping the cache slice. First NON-ZERO wins: `??` alone
        // would let an explicit `cache_read_tokens => 0` shadow a non-zero
        // `cache_hit_tokens` alias (0 is not null), re-introducing exactly the
        // dropped-cache-slice bug this block exists to fix.
        $cacheReadTokens  = (int) ($data['cache_read_tokens'] ?? 0);
        if ($cacheReadTokens === 0) {
            $cacheReadTokens = (int) ($data['cache_hit_tokens'] ?? 0);
        }
        $cacheWriteTokens = (int) ($data['cache_write_tokens'] ?? 0);

        $cost = $data['cost_usd'] ?? null;
        $shadow = $data['shadow_cost_usd'] ?? null;
        $billingModel = null;
        $costSource = $cost !== null ? 'caller' : null;

        if ($this->costs) {
            if ($cost === null) {
                $cost = $this->costs->calculate(
                    $model, $inputTokens, $outputTokens, $backend,
                    $cacheReadTokens, $cacheWriteTokens
                );
                $costSource = 'calculator';
            }
            $billingModel = $this->costs->billingModel($model, $backend);
            if ($shadow === null) {
                $shadow = $billingModel === CostCalculator::BILLING_SUBSCRIPTION
                    ? $this->costs->shadowCalculate($model, $inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens)
                    : $cost;
            }
        }

        // Preserve any caller-supplied metadata + tag with cache / cost-source
        // info so the dashboard can explain "why does this row's cost differ
        // from my CostCalculator output".
        $metadata = $data['metadata'] ?? [];
        if (!is_array($metadata)) $metadata = ['value' => $metadata];
        if ($cacheReadTokens)  $metadata['cache_read_tokens']  = $cacheReadTokens;
        if ($cacheWriteTokens) $metadata['cache_write_tokens'] = $cacheWriteTokens;
        if ($costSource)       $metadata['cost_source']        = $costSource;

        // cache_hit_rate ∈ [0, 1] — fraction of the prompt that hit the
        // provider's prefix cache. The denominator is the GROSS prompt
        // (uncached input + cache reads); after SDK 0.9.6 the SDK
        // already subtracts cached_tokens from `input_tokens`, so we
        // reconstruct the gross count here. Only stamped when there is
        // a cache hit AND a non-zero gross prompt — otherwise dashboards
        // get a misleading 0.0 for rows with no cache activity at all.
        $grossPromptTokens = $inputTokens + $cacheReadTokens;
        if ($cacheReadTokens > 0 && $grossPromptTokens > 0) {
            $metadata['cache_hit_rate'] = round($cacheReadTokens / $grossPromptTokens, 4);
        }

        return $this->usage->record([
            'backend'         => $backend,
            'provider_id'     => $data['provider_id'] ?? null,
            'service_id'      => $data['service_id'] ?? null,
            'model'           => $model,
            'task_type'       => $data['task_type'] ?? null,
            'capability'      => $data['capability'] ?? null,
            'input_tokens'    => $inputTokens,
            'output_tokens'   => $outputTokens,
            'cost_usd'        => (float) ($cost ?? 0),
            'shadow_cost_usd' => $shadow !== null ? (float) $shadow : null,
            'billing_model'   => $billingModel,
            'duration_ms'     => $data['duration_ms'] ?? null,
            'user_id'         => $data['user_id'] ?? null,
            'metadata'        => $metadata ?: null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);
    }
}
