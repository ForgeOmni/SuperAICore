<?php

namespace SuperAICore\Http\Controllers;

use Carbon\Carbon;
use SuperAICore\Contracts\UsageRepository;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\EngineCatalog;
use Illuminate\Routing\Controller;

/**
 * Cost Analytics — reads via UsageRepository so host apps can plug in
 * alternate sources (e.g. SuperTeam's task_results.metadata.usage).
 *
 * Subscription engines (e.g. GitHub Copilot) are split into a separate
 * panel so monthly USD totals don't get diluted by zero-cost rows.
 */
class CostDashboardController extends Controller
{
    public function index(UsageRepository $usage, CostCalculator $costs, EngineCatalog $engines)
    {
        $subscriptionBackends = [];
        foreach ($engines->all() as $engine) {
            if ($engine->billingModel === CostCalculator::BILLING_SUBSCRIPTION) {
                foreach ($engine->dispatcherBackends as $backend) {
                    $subscriptionBackends[$backend] = $engine->key;
                }
            }
        }

        $allRows = collect($usage->all())->map(function ($r) use ($costs) {
            $backend = $r['backend'] ?? 'unknown';
            $model   = $r['model']   ?? 'unknown';
            // Prefer the row-stamped billing_model (honours any historical
            // override when it was recorded) and fall back to the current
            // catalog so pre-0.6.1 rows still classify.
            $billing = $r['billing_model'] ?? $costs->billingModel($model, $backend);
            return (object) [
                'cost_usd'        => (float) ($r['cost_usd'] ?? 0),
                'shadow_cost_usd' => (float) ($r['shadow_cost_usd'] ?? 0),
                'input_tokens'    => (int) ($r['input_tokens'] ?? 0),
                'output_tokens'   => (int) ($r['output_tokens'] ?? 0),
                'model'           => $model,
                'backend'         => $backend,
                'billing_model'   => $billing,
                'task_type'       => $r['task_type'] ?? 'unknown',
                'provider_id'     => $r['provider_id'] ?? null,
                'created_at'      => isset($r['created_at']) ? (Carbon::make($r['created_at']) ?: null) : null,
            ];
        })->filter(fn ($r) => $r->task_type !== 'test_connection');

        $rows             = $allRows->where('billing_model', CostCalculator::BILLING_USAGE);
        $subscriptionRows = $allRows->where('billing_model', CostCalculator::BILLING_SUBSCRIPTION);

        $summary = [
            'total_cost'         => (float) $rows->sum('cost_usd'),
            'total_tasks'        => $rows->count(),
            'avg_cost_per_task'  => $rows->count() > 0 ? $rows->sum('cost_usd') / $rows->count() : 0,
            'total_input_tokens' => (int) $rows->sum('input_tokens'),
            'total_output_tokens'=> (int) $rows->sum('output_tokens'),
            'total_tokens'       => (int) ($rows->sum('input_tokens') + $rows->sum('output_tokens')),
            // Subscription panel surfaces call counts AND shadow USD so
            // operators can compare subscription throughput against
            // pay-as-you-go spend on the same scale.
            'subscription_tasks'       => $subscriptionRows->count(),
            'subscription_shadow_cost' => (float) $subscriptionRows->sum('shadow_cost_usd'),
            'subscription_tokens'      => (int) ($subscriptionRows->sum('input_tokens') + $subscriptionRows->sum('output_tokens')),
        ];

        $thirtyDaysAgo = Carbon::now()->subDays(30)->startOfDay();
        $recent = $rows->filter(fn ($r) => $r->created_at && $r->created_at->gte($thirtyDaysAgo));

        $byDay      = $recent->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))
            ->map(fn ($g) => $this->aggregate($g))->sortKeysDesc();
        $byModel    = $rows->groupBy('model')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byTaskType = $rows->groupBy('task_type')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byBackend  = $rows->groupBy('backend')->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');
        $byProvider = $rows->groupBy(fn ($r) => $r->provider_id ? "provider_{$r->provider_id}" : 'builtin')
            ->map(fn ($g) => $this->aggregate($g))->sortByDesc('cost');

        $subscriptionByBackend = $subscriptionRows->groupBy('backend')
            ->map(fn ($g) => $this->aggregate($g))->sortByDesc('count');
        $subscriptionByModel = $subscriptionRows->groupBy('model')
            ->map(fn ($g) => $this->aggregate($g))->sortByDesc('count');

        return view('super-ai-core::costs.index', compact(
            'summary', 'byDay', 'byModel', 'byTaskType', 'byBackend', 'byProvider',
            'subscriptionByBackend', 'subscriptionByModel'
        ));
    }

    protected function aggregate($group): array
    {
        $cost = (float) $group->sum('cost_usd');
        $shadow = (float) $group->sum('shadow_cost_usd');
        $count = $group->count();
        $input = (int) $group->sum('input_tokens');
        $output = (int) $group->sum('output_tokens');
        return [
            'count' => $count,
            'cost' => $cost,
            'shadow_cost' => $shadow,
            'avg_cost' => $count > 0 ? $cost / $count : 0,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'avg_tokens' => $count > 0 ? ($input + $output) / $count : 0,
        ];
    }
}
