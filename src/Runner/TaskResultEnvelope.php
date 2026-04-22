<?php

namespace SuperAICore\Runner;

/**
 * Standardized result shape returned by {@see TaskRunner::run()}.
 *
 * Replaces the ad-hoc `['success', 'exit_code', 'output', 'summary',
 * 'usage']` arrays each downstream host invented for itself. Hosts that
 * adopt `TaskRunner` get a single typed object back regardless of which
 * backend ran, so post-processing (saving to TaskResult, updating UI,
 * writing audit logs) doesn't branch on backend-specific result shapes.
 *
 * Design notes:
 *   - All properties are public + readonly so hosts can destructure
 *     freely without method-call ceremony.
 *   - Cost / shadow / billing live as separate fields rather than nested
 *     in `usage` so the dashboard query path doesn't have to dig.
 *   - `usageLogId` lets hosts correlate the row that Dispatcher wrote
 *     into `ai_usage_logs` (e.g. to attach later metadata, or to skip
 *     duplicate recording — the host-side `_usage_recorded` sentinel
 *     becomes superfluous).
 *   - `spawnReport` is reserved for Phase C of the host-spawn-uplift
 *     roadmap, when `AgentSpawn\Pipeline` populates it with the
 *     fan-out + consolidation report. Phase B leaves it null.
 *   - `error` carries a human-readable message when `success === false`
 *     and the failure mode wasn't surfaced through `output` (e.g.
 *     Dispatcher returned null because no provider was configured).
 */
final class TaskResultEnvelope
{
    /**
     * @param array<string,mixed> $usage
     * @param array<int,mixed>|null $spawnReport
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $summary,
        public readonly array $usage,
        public readonly ?float $costUsd = null,
        public readonly ?float $shadowCostUsd = null,
        public readonly ?string $billingModel = null,
        public readonly ?string $model = null,
        public readonly ?string $backend = null,
        public readonly int $durationMs = 0,
        public readonly ?string $logFile = null,
        public readonly ?int $usageLogId = null,
        public readonly ?array $spawnReport = null,
        public readonly ?string $error = null,
    ) {}

    /**
     * Convenience for the "Dispatcher couldn't even run the prompt"
     * failure mode — no usage to record, no log content beyond the
     * error reason.
     */
    public static function failed(
        int $exitCode = 1,
        ?string $logFile = null,
        ?string $error = null,
        ?string $backend = null,
    ): self {
        return new self(
            success: false,
            exitCode: $exitCode,
            output: $error ?? '',
            summary: $error ?? '',
            usage: [],
            backend: $backend,
            logFile: $logFile,
            error: $error,
        );
    }

    /**
     * Plain-array projection — useful when a host's existing storage
     * layer (e.g. an Eloquent `update()` call expecting an array) hasn't
     * been migrated to the typed envelope yet.
     */
    public function toArray(): array
    {
        return [
            'success'         => $this->success,
            'exit_code'       => $this->exitCode,
            'output'          => $this->output,
            'summary'         => $this->summary,
            'usage'           => $this->usage,
            'cost_usd'        => $this->costUsd,
            'shadow_cost_usd' => $this->shadowCostUsd,
            'billing_model'   => $this->billingModel,
            'model'           => $this->model,
            'backend'         => $this->backend,
            'duration_ms'     => $this->durationMs,
            'log_file'        => $this->logFile,
            'usage_log_id'    => $this->usageLogId,
            'spawn_report'    => $this->spawnReport,
            'error'           => $this->error,
        ];
    }
}
