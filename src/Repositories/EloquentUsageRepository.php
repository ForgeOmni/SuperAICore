<?php

namespace SuperAICore\Repositories;

use SuperAICore\Contracts\ScopedUsageRepository;
use SuperAICore\Models\AiUsageLog;

class EloquentUsageRepository implements ScopedUsageRepository
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

        return AiUsageLog::create($this->onlyFillable($data))->id;
    }

    /**
     * Filter the input array down to AiUsageLog's fillable columns so
     * extra envelope fields the host added (e.g. `pre_snapshot` /
     * `post_snapshot` / `file_diff_summary` on hosts that haven't run
     * the 2026_05_20 migration yet) don't trip Eloquent's MassAssignment
     * guard or hit a non-existent column on legacy schemas. Falls back
     * to the raw row when the model doesn't expose fillable.
     */
    private function onlyFillable(array $data): array
    {
        $fillable = (new AiUsageLog())->getFillable();
        if ($fillable === []) return $data;
        return array_intersect_key($data, array_flip($fillable));
    }

    public function summary(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        return $this->summarise($this->window(null, null, $from, $to)->get());
    }

    /**
     * One scope's spend. `$scopeId` null means every row in that scope.
     *
     * @since 1.2.0
     */
    public function summaryForScope(
        string $scope,
        ?int $scopeId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        return $this->summarise($this->window($scope, $scopeId, $from, $to)->get());
    }

    /**
     * Raw rows for one scope, for a host's own aggregation.
     *
     * @since 1.2.0
     */
    public function allForScope(
        string $scope,
        ?int $scopeId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        return $this->window($scope, $scopeId, $from, $to)->get()->map(fn ($r) => $r->toArray())->all();
    }

    /**
     * Rows in a time window, optionally narrowed to one scope. The `scope`
     * column arrived in 1.2.0; a host that has not run the migration gets the
     * unscoped query rather than a SQL error about an unknown column.
     */
    private function window(
        ?string $scope,
        ?int $scopeId,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to
    ): \Illuminate\Database\Eloquent\Builder {
        $q = AiUsageLog::query();
        if ($from) $q->where('created_at', '>=', $from);
        if ($to) $q->where('created_at', '<=', $to);

        if ($scope !== null && $this->hasScopeColumns()) {
            $q->where('scope', $scope);
            if ($scopeId !== null) {
                $q->where('scope_id', $scopeId);
            }
        }

        return $q;
    }

    private function hasScopeColumns(): bool
    {
        static $has = null;

        if ($has === null) {
            try {
                $has = (new AiUsageLog())->getConnection()
                    ->getSchemaBuilder()
                    ->hasColumn((new AiUsageLog())->getTable(), 'scope');
            } catch (\Throwable) {
                $has = false;
            }
        }

        return $has;
    }

    /** @param \Illuminate\Support\Collection $rows */
    private function summarise($rows): array
    {
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
        if (!empty($filters['scope']) && $this->hasScopeColumns()) {
            $q->where('scope', $filters['scope']);
            if (!empty($filters['scope_id'])) $q->where('scope_id', $filters['scope_id']);
        }

        return $q->limit($limit)->get()->map(fn ($r) => $r->toArray())->all();
    }

    public function purgeOlderThan(\DateTimeInterface $cutoff): int
    {
        return AiUsageLog::where('created_at', '<', $cutoff)->delete();
    }

    public function all(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        return $this->window(null, null, $from, $to)->get()->map(fn ($r) => $r->toArray())->all();
    }

    /**
     * Look up the most recent ai_usage_logs row whose
     * `metadata->session_id` matches and whose backend is one of the
     * filter list. Used by Dispatcher::detectCacheCold(). Returns null
     * when the session has no prior rows in the chosen backend set.
     *
     * Driver-specific JSON access:
     *   - MySQL/MariaDB:  metadata->>'$.session_id' = ?
     *   - PostgreSQL:     metadata->>'session_id' = ?
     *   - SQLite:         json_extract(metadata, '$.session_id') = ?
     *
     * Eloquent's `whereJsonContains` doesn't fit (we want strict equality
     * on a scalar, not array containment), but `where('metadata->session_id')`
     * compiles to the right per-driver expression for MySQL/PG/SQLite.
     */
    public function findLatestForSession(string $sessionId, array $backends): ?array
    {
        if ($sessionId === '' || $backends === []) return null;
        try {
            $row = AiUsageLog::query()
                ->where('metadata->session_id', $sessionId)
                ->whereIn('backend', $backends)
                ->orderByDesc('created_at')
                ->limit(1)
                ->first(['id', 'backend', 'model', 'created_at']);
        } catch (\Throwable) {
            // JSON path not supported by the active driver, or column missing.
            return null;
        }
        if ($row === null) return null;
        return [
            'id'         => (int) $row->id,
            'backend'    => (string) $row->backend,
            'model'      => (string) $row->model,
            'created_at' => (string) $row->created_at,
        ];
    }
}
