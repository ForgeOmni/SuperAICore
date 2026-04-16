<?php

namespace SuperAICore\Contracts;

interface UsageRepository
{
    /**
     * Record a single LLM call.
     *
     * @param array $data  {
     *   backend: string,
     *   provider_id: ?int,
     *   service_id: ?int,
     *   model: string,
     *   task_type: ?string,
     *   capability: ?string,
     *   input_tokens: int,
     *   output_tokens: int,
     *   cost_usd: float,
     *   duration_ms: int,
     *   user_id: ?int,
     *   metadata: ?array,
     * }
     */
    public function record(array $data): int;

    /**
     * Aggregate usage within a time window.
     * @return array {total_runs, total_input_tokens, total_output_tokens, total_cost_usd, by_model[], by_backend[]}
     */
    public function summary(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array;

    /** @return array[] recent entries */
    public function recent(int $limit = 50, array $filters = []): array;

    public function purgeOlderThan(\DateTimeInterface $cutoff): int;

    /**
     * Raw row-set for custom aggregations. Each row is an associative
     * array with keys: backend, model, task_type, input_tokens,
     * output_tokens, cost_usd, created_at (Carbon|string).
     *
     * Used by CostDashboardController / UsageController for group-by.
     *
     * @return array[]
     */
    public function all(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array;
}
