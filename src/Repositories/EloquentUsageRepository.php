<?php

namespace SuperAICore\Repositories;

use SuperAICore\Contracts\UsageRepository;
use SuperAICore\Models\AiUsageLog;

class EloquentUsageRepository implements UsageRepository
{
    /**
     * Idempotency window — a record() call with an `idempotency_key`
     * that matches a row written within this many seconds short-circuits
     * to returning that row's id instead of inserting a duplicate.
     *
     * 60s is long enough to absorb host-side accidental double-records
     * (Dispatcher writing + a host that also calls UsageRecorder for the
     * same turn) but short enough that two genuinely separate runs that
     * happen to share a key don't get falsely deduped.
     */
    public const IDEMPOTENCY_WINDOW_SECONDS = 60;

    public function record(array $data): int
    {
        $key = $data['idempotency_key'] ?? null;
        if ($key !== null && $key !== '') {
            $existing = AiUsageLog::query()
                ->where('idempotency_key', $key)
                ->where('created_at', '>=', now()->subSeconds(self::IDEMPOTENCY_WINDOW_SECONDS))
                ->orderByDesc('created_at')
                ->value('id');
            if ($existing !== null) {
                return (int) $existing;
            }
        }

        return AiUsageLog::create($data)->id;
    }

    public function summary(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $q = AiUsageLog::query();
        if ($from) $q->where('created_at', '>=', $from);
        if ($to) $q->where('created_at', '<=', $to);

        $rows = $q->get();
        $total = $rows->count();
        $inputTokens = $rows->sum('input_tokens');
        $outputTokens = $rows->sum('output_tokens');
        $cost = (float) $rows->sum('cost_usd');

        $byModel = $rows->groupBy('model')->map(fn ($g) => [
            'runs' => $g->count(),
            'input_tokens' => $g->sum('input_tokens'),
            'output_tokens' => $g->sum('output_tokens'),
            'cost_usd' => (float) $g->sum('cost_usd'),
        ])->all();

        $byBackend = $rows->groupBy('backend')->map(fn ($g) => [
            'runs' => $g->count(),
            'cost_usd' => (float) $g->sum('cost_usd'),
        ])->all();

        return [
            'total_runs' => $total,
            'total_input_tokens' => $inputTokens,
            'total_output_tokens' => $outputTokens,
            'total_cost_usd' => $cost,
            'by_model' => $byModel,
            'by_backend' => $byBackend,
        ];
    }

    public function recent(int $limit = 50, array $filters = []): array
    {
        $q = AiUsageLog::orderByDesc('created_at');
        if (!empty($filters['model'])) $q->where('model', $filters['model']);
        if (!empty($filters['task_type'])) $q->where('task_type', $filters['task_type']);
        if (!empty($filters['user_id'])) $q->where('user_id', $filters['user_id']);
        if (!empty($filters['backend'])) $q->where('backend', $filters['backend']);

        return $q->limit($limit)->get()->map(fn ($r) => $r->toArray())->all();
    }

    public function purgeOlderThan(\DateTimeInterface $cutoff): int
    {
        return AiUsageLog::where('created_at', '<', $cutoff)->delete();
    }

    public function all(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $q = AiUsageLog::query();
        if ($from) $q->where('created_at', '>=', $from);
        if ($to) $q->where('created_at', '<=', $to);
        return $q->get()->map(fn ($r) => $r->toArray())->all();
    }
}
