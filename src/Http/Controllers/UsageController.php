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
        // Defaults to off so /usage still shows full traffic on first visit;
        // toggling it on reveals only rows the dispatcher flagged with a
        // cache_warning (jcode-style cache-cold heuristic, see Dispatcher).
        $coldOnly = $filtersApplied ? $request->boolean('cold_only') : false;

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
        if ($coldOnly) {
            $q->whereNotNull('metadata->cache_warning');
        }

        $logs = $q->orderByDesc('created_at')->limit(500)->get();

        $coldCount = $logs->filter(fn ($r) => !empty(($r->metadata ?? [])['cache_warning']))->count();

        // 0.9.7 — usage_source split (user / ambient / host-defined).
        // Reads `metadata.usage_source` (Dispatcher writes 'user' as the
        // default); rows older than 0.9.7 lack the key and bucket as
        // 'user' for backwards compatibility on the dashboard.
        $bySource = $logs->groupBy(
            fn ($r) => (string) (($r->metadata ?? [])['usage_source'] ?? 'user')
        )->map(fn ($g) => [
            'runs'        => $g->count(),
            'cost'        => (float) $g->sum('cost_usd'),
            'shadow_cost' => (float) $g->sum('shadow_cost_usd'),
        ])->sortByDesc(fn ($r) => max($r['cost'], $r['shadow_cost']));

        $ambientRuns = (int) ($bySource['ambient']['runs'] ?? 0);
        $ambientCost = (float) ($bySource['ambient']['cost'] ?? 0);

        // Cache-hit aggregates — pulled from metadata.cache_read_tokens
        // (UsageRecorder writes the field whenever the provider returned
        // a non-zero cache slice). The hit rate is gross-prompt-relative
        // so the dashboard answers "what fraction of my paid prompt was
        // free this period?" — the same question DeepSeek-TUI asks at
        // turn-end, just aggregated.
        $cacheReadTotal = $logs->sum(fn ($r) => (int) (($r->metadata ?? [])['cache_read_tokens'] ?? 0));
        $grossPromptTotal = (int) $logs->sum('input_tokens') + (int) $cacheReadTotal;
        $cacheHitRate = $grossPromptTotal > 0 ? round($cacheReadTotal / $grossPromptTotal, 4) : 0.0;

        $summary = [
            'total_runs'           => $logs->count(),
            'total_cost'           => (float) $logs->sum('cost_usd'),
            'total_shadow_cost'    => (float) $logs->sum('shadow_cost_usd'),
            'total_input_tokens'   => (int) $logs->sum('input_tokens'),
            'total_output_tokens'  => (int) $logs->sum('output_tokens'),
            'total_cache_cold'     => $coldCount,
            'total_ambient_runs'   => $ambientRuns,
            'total_ambient_cost'   => $ambientCost,
            'total_cache_read_tokens' => (int) $cacheReadTotal,
            'cache_hit_rate'       => $cacheHitRate,
        ];

        $byModel = $logs->groupBy('model')->map(function ($g) {
            $cacheRead = $g->sum(fn ($r) => (int) (($r->metadata ?? [])['cache_read_tokens'] ?? 0));
            $gross = (int) $g->sum('input_tokens') + (int) $cacheRead;
            return [
                'runs'              => $g->count(),
                'cost'              => (float) $g->sum('cost_usd'),
                'shadow_cost'       => (float) $g->sum('shadow_cost_usd'),
                'input_tokens'      => (int) $g->sum('input_tokens'),
                'output_tokens'     => (int) $g->sum('output_tokens'),
                'cache_read_tokens' => (int) $cacheRead,
                'cache_hit_rate'    => $gross > 0 ? round($cacheRead / $gross, 4) : 0.0,
            ];
        })->sortByDesc(fn ($r) => max($r['cost'], $r['shadow_cost']));

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
            'logs', 'summary', 'byModel', 'byBackend', 'byTaskType', 'bySource',
            'filters', 'days', 'hideEmpty', 'hideTests', 'coldOnly',
            'allModels', 'allTaskTypes', 'allBackends',
            'providers', 'services'
        ));
    }
}
