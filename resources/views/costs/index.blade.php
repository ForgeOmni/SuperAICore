@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.costs'))
@section('content')
<h4><i class="bi bi-cash-stack me-1"></i>{{ __('super-ai-core::messages.costs') }}</h4>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center"><div class="h3 mb-0">${{ number_format($summary['total_cost'], 2) }}</div><div class="small text-muted">Total cost</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center"><div class="h3 mb-0">{{ $summary['total_tasks'] }}</div><div class="small text-muted">Calls</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center"><div class="h3 mb-0">${{ number_format($summary['avg_cost_per_task'], 2) }}</div><div class="small text-muted">Avg / call</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center"><div class="h3 mb-0">{{ number_format($summary['total_tokens']) }}</div><div class="small text-muted">Total tokens</div></div></div></div>
</div>

@if(!empty($savings) && $savings['shadow_cost_total'] > 0)
<div class="row g-3 mb-3">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm" style="background: linear-gradient(90deg, #ecfdf5 0%, #d1fae5 100%);">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="small text-muted text-uppercase">Savings (routing strategy)</div>
                        <div class="h2 mb-0 text-success">${{ number_format($savings['saved_total'], 2) }}</div>
                        <div class="small text-muted">
                            shadow ${{ number_format($savings['shadow_cost_total'], 2) }}
                            − actual ${{ number_format($savings['actual_cost_total'], 2) }}
                            = {{ number_format($savings['savings_ratio'] * 100, 1) }}% saved
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted text-uppercase">Last 30 days</div>
                        <div class="h3 mb-0 text-success">${{ number_format($savings['saved_30d'], 2) }}</div>
                        <div class="small text-muted">vs pay-as-you-go pricing</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if(!empty($savingsByBackend) && count($savingsByBackend) > 0)
<div class="row g-3 mb-3">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">Savings by backend (rows with shadow cost)</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Backend</th><th class="text-end">Calls</th><th class="text-end">Shadow</th><th class="text-end">Actual</th><th class="text-end">Saved</th><th class="text-end">Ratio</th></tr></thead>
                    <tbody>
                    @foreach($savingsByBackend as $backend => $s)
                        <tr>
                            <td><code>{{ $backend }}</code></td>
                            <td class="text-end">{{ $s['count'] }}</td>
                            <td class="text-end text-muted">${{ number_format($s['shadow_cost'], 4) }}</td>
                            <td class="text-end">${{ number_format($s['actual_cost'], 4) }}</td>
                            <td class="text-end text-success">${{ number_format($s['saved'], 4) }}</td>
                            <td class="text-end">{{ number_format($s['ratio'] * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
@endif

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">Daily breakdown (30d)</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Date</th><th class="text-end">Calls</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                @foreach($byDay as $date => $d)
                    <tr><td>{{ $date }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">${{ number_format($d['cost'], 2) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Model</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Model</th><th class="text-end">Calls</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                @foreach($byModel as $m => $d)
                    <tr><td class="small font-monospace">{{ $m }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">${{ number_format($d['cost'], 2) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Task Type</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Type</th><th class="text-end">Calls</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                @foreach($byTaskType as $t => $d)
                    <tr><td>{{ $t }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">${{ number_format($d['cost'], 2) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Backend</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Backend</th><th class="text-end">Calls</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                @foreach($byBackend as $b => $d)
                    <tr><td>{{ $b }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">${{ number_format($d['cost'], 2) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
</div>

@if(($summary['subscription_tasks'] ?? 0) > 0)
<h5 class="mt-4"><i class="bi bi-card-checklist me-1"></i>Subscription engines <span class="badge bg-secondary">{{ $summary['subscription_tasks'] }} calls</span></h5>
<p class="small text-muted mb-2">
    Flat-fee plans (e.g. GitHub Copilot, Claude Code builtin). Billed cost is $0; the
    <span class="text-info">shadow</span> column shows what the same tokens would cost on pay-as-you-go —
    useful for comparing subscription throughput against usage-billed spend.
</p>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h3 mb-0 text-info">~${{ number_format($summary['subscription_shadow_cost'], 2) }}</div>
                <div class="small text-muted">Shadow cost (est.)</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h3 mb-0">{{ number_format($summary['subscription_tokens']) }}</div>
                <div class="small text-muted">Total tokens (sub)</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h3 mb-0">{{ $summary['subscription_tasks'] }}</div>
                <div class="small text-muted">Subscription calls</div>
            </div>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Backend</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Backend</th><th class="text-end">Calls</th><th class="text-end">Tokens</th><th class="text-end text-info">Shadow</th></tr></thead>
                <tbody>
                @foreach($subscriptionByBackend as $b => $d)
                    <tr><td>{{ $b }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">{{ number_format($d['total_tokens']) }}</td><td class="text-end text-info">${{ number_format($d['shadow_cost'], 4) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Model</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Model</th><th class="text-end">Calls</th><th class="text-end">Tokens</th><th class="text-end text-info">Shadow</th></tr></thead>
                <tbody>
                @foreach($subscriptionByModel as $m => $d)
                    <tr><td class="small font-monospace">{{ $m }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">{{ number_format($d['total_tokens']) }}</td><td class="text-end text-info">${{ number_format($d['shadow_cost'], 4) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
