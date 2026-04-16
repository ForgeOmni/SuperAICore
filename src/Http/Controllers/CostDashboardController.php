<?php

namespace SuperAICore\Http\Controllers;

use Carbon\Carbon;
use SuperAICore\Contracts\UsageRepository;
use Illuminate\Routing\Controller;

/**
 * Cost Analytics — reads via UsageRepository so host apps can plug in
 * alternate sources (e.g. SuperTeam's task_results.metadata.usage).
 */
class CostDashboardController extends Controller
{
    public function index(UsageRepository $usage)
    {
        $rows = collect($usage->all())->map(fn ($r) => (object) [
            'cost_usd' => (float) ($r['cost_usd'] ?? 0),
            'input_tokens' => (int) ($r['input_tokens'] ?? 0),
            'output_tokens' => (int) ($r['output_tokens'] ?? 0),
            'model' => $r['model'] ?? 'unknown',
            'backend' => $r['backend'] ?? 'unknown',
            'task_type' => $r['task_type'] ?? 'unknown',
            'provider_id' => $r['provider_id'] ?? null,
            'created_at' => isset($r['created_at']) ? (Carbon::make($r['created_at']) ?: null) : null,
        ]);

        $summary = [
            'total_cost' => (float) $rows->sum('cost_usd'),
            'total_tasks' => $rows->count(),
            'avg_cost_per_task' => $rows->count() > 0 ? $rows->sum('cost_usd') / $rows->count() : 0,
            'total_input_tokens' => (int) $rows->sum('input_tokens'),
            'total_output_tokens' => (int) $rows->sum('output_tokens'),
            'total_tokens' => (int) ($rows->sum('input_tokens') + $rows->sum('output_tokens')),
        ];

        $thirtyDaysAgo = Carbon::now()->subDays(30)->startOfDay();
        $recent = $rows->filter(fn ($r) => $r->created_at && $r->created_at->gte($thirtyDaysAgo));

        $byDay = $recent->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))
            ->map(fn ($g) => $this->aggregate($g))->sortKeysDesc();
        $byModel = $rows->groupBy('model')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byTaskType = $rows->groupBy('task_type')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byBackend = $rows->groupBy('backend')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byProvider = $rows->groupBy(fn ($r) => $r->provider_id ? "provider_{$r->provider_id}" : 'builtin')
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
