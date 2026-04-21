<?php

namespace SuperAICore\Http\Controllers;

use Carbon\Carbon;
use SuperAICore\Models\AiUsageLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Usage page — reads from ai_usage_logs and displays summary + filters.
 */
class UsageController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only(['model', 'task_type', 'user_id', 'backend']);
        $days = (int) $request->input('days', 30);
        $hideEmpty = (bool) $request->boolean('hide_empty', true);    // default on — hide 0-token noise
        $hideTests = (bool) $request->boolean('hide_tests', true);    // default on — hide test_connection
        $from = Carbon::now()->subDays($days)->startOfDay();

        $q = AiUsageLog::where('created_at', '>=', $from);
        foreach ($filters as $col => $val) {
            if ($val !== null && $val !== '') $q->where($col, $val);
        }
        if ($hideEmpty) {
            $q->where(function ($qq) {
                $qq->where('input_tokens', '>', 0)->orWhere('output_tokens', '>', 0);
            });
        }
        if ($hideTests) {
            $q->where(function ($qq) {
                $qq->whereNull('task_type')->orWhere('task_type', '!=', 'test_connection');
            });
        }

        $logs = $q->orderByDesc('created_at')->limit(500)->get();

        $summary = [
            'total_runs'          => $logs->count(),
            'total_cost'          => (float) $logs->sum('cost_usd'),
            'total_shadow_cost'   => (float) $logs->sum('shadow_cost_usd'),
            'total_input_tokens'  => (int) $logs->sum('input_tokens'),
            'total_output_tokens' => (int) $logs->sum('output_tokens'),
        ];

        $byModel = $logs->groupBy('model')->map(fn ($g) => [
            'runs'          => $g->count(),
            'cost'          => (float) $g->sum('cost_usd'),
            'shadow_cost'   => (float) $g->sum('shadow_cost_usd'),
            'input_tokens'  => (int) $g->sum('input_tokens'),
            'output_tokens' => (int) $g->sum('output_tokens'),
        ])->sortByDesc(fn ($r) => max($r['cost'], $r['shadow_cost']));

        $byBackend = $logs->groupBy('backend')->map(fn ($g) => [
            'runs'        => $g->count(),
            'cost'        => (float) $g->sum('cost_usd'),
            'shadow_cost' => (float) $g->sum('shadow_cost_usd'),
        ])->sortByDesc(fn ($r) => max($r['cost'], $r['shadow_cost']));

        $byTaskType = $logs->filter(fn ($r) => !empty($r->task_type))
            ->groupBy('task_type')->map(fn ($g) => [
                'runs'          => $g->count(),
                'cost'          => (float) $g->sum('cost_usd'),
                'shadow_cost'   => (float) $g->sum('shadow_cost_usd'),
                'input_tokens'  => (int) $g->sum('input_tokens'),
                'output_tokens' => (int) $g->sum('output_tokens'),
            ])->sortByDesc('runs');

        $allModels = AiUsageLog::distinct()->pluck('model');
        $allTaskTypes = AiUsageLog::distinct()->pluck('task_type');
        $allBackends = AiUsageLog::distinct()->pluck('backend');

        return view('super-ai-core::usage.index', compact(
            'logs', 'summary', 'byModel', 'byBackend', 'byTaskType',
            'filters', 'days', 'hideEmpty', 'hideTests',
            'allModels', 'allTaskTypes', 'allBackends'
        ));
    }
}
