<?php

namespace SuperAICore\Http\Controllers;

use Carbon\Carbon;
use SuperAICore\Models\AiUsageLog;
use Illuminate\Routing\Controller;

/**
 * Cost Analytics Dashboard.
 *
 * Reads from the generic `ai_usage_logs` table (populated by Dispatcher).
 * Host apps are responsible for gating access (e.g. super-admin middleware).
 */
class CostDashboardController extends Controller
{
    public function index()
    {
        $logs = AiUsageLog::query()->get();

        $summary = [
            'total_cost' => (float) $logs->sum('cost_usd'),
            'total_tasks' => $logs->count(),
            'avg_cost_per_task' => $logs->count() > 0 ? $logs->sum('cost_usd') / $logs->count() : 0,
            'total_input_tokens' => (int) $logs->sum('input_tokens'),
            'total_output_tokens' => (int) $logs->sum('output_tokens'),
            'total_tokens' => (int) ($logs->sum('input_tokens') + $logs->sum('output_tokens')),
        ];

        // Daily breakdown (last 30 days)
        $thirtyDaysAgo = Carbon::now()->subDays(30)->startOfDay();
        $recent = $logs->filter(fn ($l) => $l->created_at && $l->created_at->gte($thirtyDaysAgo));

        $byDay = $recent->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))
            ->map(fn ($g) => $this->aggregate($g))
            ->sortKeysDesc();

        $byModel = $logs->groupBy('model')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byTaskType = $logs->groupBy(fn ($l) => $l->task_type ?? 'unknown')
            ->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byBackend = $logs->groupBy('backend')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byProvider = $logs->groupBy(fn ($l) => $l->provider_id ? "provider_{$l->provider_id}" : 'builtin')
            ->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');

        return view('super-ai-core::costs.index', compact(
            'summary', 'byDay', 'byModel', 'byTaskType', 'byBackend', 'byProvider'
        ));
    }

    protected function aggregate($group): array
    {
        $cost = (float) $group->sum('cost_usd');
        $count = $group->count();
        $input = (int) $group->sum('input_tokens');
        $output = (int) $group->sum('output_tokens');
        return [
            'count' => $count,
            'cost' => $cost,
            'avg_cost' => $count > 0 ? $cost / $count : 0,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'avg_tokens' => $count > 0 ? ($input + $output) / $count : 0,
        ];
    }
}
