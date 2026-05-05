<?php

namespace SuperAICore\Http\Controllers;

use Carbon\Carbon;
use SuperAICore\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Headless JSON API for usage data — mirrors codex's app-server
 * `/v1/usage` endpoint shape so dashboards and CI / billing
 * automation can read aggregate usage without scraping the HTML
 * dashboard.
 *
 * Single endpoint, four group-by axes:
 *
 *   GET /v1/usage?group_by=day|model|provider|thread
 *
 * The response shape is identical across axes; only the bucket key
 * differs. Filters from the HTML controller (model, task_type,
 * user_id, backend) are honoured here too — same query-string keys.
 *
 * Authentication / authorisation is the host's job — wrap the route
 * in whatever middleware your app uses (auth:sanctum, signed urls,
 * etc.). The controller does not assume a session is present.
 */
class UsageApiController extends Controller
{
    private const ALLOWED_GROUP_BY = ['day', 'model', 'provider', 'thread', 'backend', 'task_type'];

    public function aggregate(Request $request): JsonResponse
    {
        $groupBy = strtolower(trim((string) $request->input('group_by', 'day')));
        if (! in_array($groupBy, self::ALLOWED_GROUP_BY, true)) {
            return response()->json([
                'error' => "Unknown group_by '{$groupBy}'. Expected one of: "
                    . implode(', ', self::ALLOWED_GROUP_BY),
            ], 422);
        }

        $days = (int) $request->input('days', 30);
        $from = Carbon::now()->subDays(max(1, $days))->startOfDay();

        $query = AiUsageLog::where('created_at', '>=', $from);
        foreach (['model', 'task_type', 'user_id', 'backend'] as $col) {
            $val = $request->input($col);
            if ($val !== null && $val !== '') {
                $query->where($col, $val);
            }
        }

        $logs = $query->orderByDesc('created_at')->limit(5000)->get();

        $bucketKeyFn = $this->bucketKeyFor($groupBy);

        $buckets = $logs->groupBy($bucketKeyFn)->map(function ($group, $key) {
            $cacheRead = (int) $group->sum(fn ($r) => (int) (($r->metadata ?? [])['cache_read_tokens'] ?? 0));
            $input = (int) $group->sum('input_tokens');
            $gross = $input + $cacheRead;
            return [
                'bucket'              => (string) $key,
                'runs'                => $group->count(),
                'cost_usd'            => round((float) $group->sum('cost_usd'), 6),
                'shadow_cost_usd'     => round((float) $group->sum('shadow_cost_usd'), 6),
                'input_tokens'        => $input,
                'output_tokens'       => (int) $group->sum('output_tokens'),
                'cache_read_tokens'   => $cacheRead,
                'cache_hit_rate'      => $gross > 0 ? round($cacheRead / $gross, 4) : 0.0,
            ];
        })->values();

        return response()->json([
            'group_by' => $groupBy,
            'from'     => $from->toIso8601String(),
            'to'       => Carbon::now()->toIso8601String(),
            'buckets'  => $buckets,
        ]);
    }

    /**
     * Pick the column / metadata key used to group rows for the
     * requested axis. `day` truncates `created_at` to YYYY-MM-DD;
     * `thread` reads `metadata.thread_id` (the SDK stamps it on
     * every record); the rest map directly to top-level columns.
     */
    private function bucketKeyFor(string $groupBy): \Closure
    {
        return match ($groupBy) {
            'day'      => fn ($row) => $row->created_at?->toDateString() ?? 'unknown',
            'model'    => fn ($row) => (string) ($row->model ?? 'unknown'),
            'backend'  => fn ($row) => (string) ($row->backend ?? 'unknown'),
            'provider' => fn ($row) => (string) ($row->provider_id ?? 'none'),
            'task_type'=> fn ($row) => (string) ($row->task_type ?? 'none'),
            'thread'   => fn ($row) => (string) (($row->metadata ?? [])['thread_id'] ?? 'unthreaded'),
            default    => fn ($row) => 'all',
        };
    }
}
