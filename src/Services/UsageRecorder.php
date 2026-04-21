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
     *   duration_ms?: ?int,
     *   provider_id?: ?int,
     *   service_id?: ?int,
     *   user_id?: ?int,
     *   metadata?: ?array,
     *   cost_usd?: ?float,        override — skip calculator
     *   shadow_cost_usd?: ?float, override — skip calculator
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

        $cost = $data['cost_usd'] ?? null;
        $shadow = $data['shadow_cost_usd'] ?? null;
        $billingModel = null;

        if ($this->costs) {
            if ($cost === null) {
                $cost = $this->costs->calculate($model, $inputTokens, $outputTokens, $backend);
            }
            $billingModel = $this->costs->billingModel($model, $backend);
            if ($shadow === null) {
                $shadow = $billingModel === CostCalculator::BILLING_SUBSCRIPTION
                    ? $this->costs->shadowCalculate($model, $inputTokens, $outputTokens)
                    : $cost;
            }
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
            'metadata'        => $data['metadata'] ?? null,
        ]);
    }
}
