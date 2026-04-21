<?php

namespace SuperAICore\Http\Controllers;

use Carbon\Carbon;
use SuperAICore\Models\AiProvider;
use SuperAICore\Models\AiService;
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

        // Filter-toggle persistence: HTML checkboxes don't submit when
        // unchecked, so we smuggle an explicit `filters_applied=1` marker
        // alongside them. When that marker is set, the absence of a box
        // means "user turned it off"; when it's absent we fall back to the
        // default-on behaviour so a first-visit `/usage` still hides noise.
        $filtersApplied = $request->boolean('filters_applied');
        $hideEmpty = $filtersApplied ? $request->boolean('hide_empty') : true;
        $hideTests = $filtersApplied ? $request->boolean('hide_tests') : true;

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

        // Provider / service labels — one query per batch so the Recent
        // calls table can render the friendly name alongside the raw id.
        $providerIds = $logs->pluck('provider_id')->filter()->unique()->values();
        $serviceIds  = $logs->pluck('service_id')->filter()->unique()->values();
        $providers = $providerIds->isNotEmpty()
            ? AiProvider::whereIn('id', $providerIds)->pluck('name', 'id')
            : collect();
        $services = $serviceIds->isNotEmpty()
            ? AiService::whereIn('id', $serviceIds)->pluck('name', 'id')
            : collect();

        return view('super-ai-core::usage.index', compact(
            'logs', 'summary', 'byModel', 'byBackend', 'byTaskType',
            'filters', 'days', 'hideEmpty', 'hideTests',
            'allModels', 'allTaskTypes', 'allBackends',
            'providers', 'services'
        ));
    }
}
