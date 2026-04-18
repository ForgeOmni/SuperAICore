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
<p class="small text-muted mb-2">Flat-fee plans (e.g. GitHub Copilot). Per-call cost is $0; counts shown for visibility, not USD.</p>
<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Backend</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Backend</th><th class="text-end">Calls</th><th class="text-end">Tokens</th></tr></thead>
                <tbody>
                @foreach($subscriptionByBackend as $b => $d)
                    <tr><td>{{ $b }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">{{ number_format($d['total_tokens']) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Model</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Model</th><th class="text-end">Calls</th><th class="text-end">Tokens</th></tr></thead>
                <tbody>
                @foreach($subscriptionByModel as $m => $d)
                    <tr><td class="small font-monospace">{{ $m }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">{{ number_format($d['total_tokens']) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
