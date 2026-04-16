<?php

namespace ForgeOmni\AiCore\Http\Controllers;

use Carbon\Carbon;
use ForgeOmni\AiCore\Models\AiUsageLog;
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
        $from = Carbon::now()->subDays($days)->startOfDay();

        $q = AiUsageLog::where('created_at', '>=', $from);
        foreach ($filters as $col => $val) {
            if ($val !== null && $val !== '') $q->where($col, $val);
        }

        $logs = $q->orderByDesc('created_at')->limit(500)->get();

        $summary = [
            'total_runs' => $logs->count(),
            'total_cost' => (float) $logs->sum('cost_usd'),
            'total_input_tokens' => (int) $logs->sum('input_tokens'),
            'total_output_tokens' => (int) $logs->sum('output_tokens'),
        ];

        $byModel = $logs->groupBy('model')->map(fn ($g) => [
            'runs' => $g->count(),
            'cost' => (float) $g->sum('cost_usd'),
            'input_tokens' => (int) $g->sum('input_tokens'),
            'output_tokens' => (int) $g->sum('output_tokens'),
        ])->sortByDesc('cost');

        $byBackend = $logs->groupBy('backend')->map(fn ($g) => [
            'runs' => $g->count(),
            'cost' => (float) $g->sum('cost_usd'),
        ])->sortByDesc('cost');

        $allModels = AiUsageLog::distinct()->pluck('model');
        $allTaskTypes = AiUsageLog::distinct()->pluck('task_type');
        $allBackends = AiUsageLog::distinct()->pluck('backend');

        return view('ai-core::usage.index', compact(
            'logs', 'summary', 'byModel', 'byBackend',
            'filters', 'days', 'allModels', 'allTaskTypes', 'allBackends'
        ));
    }
}
