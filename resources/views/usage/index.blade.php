@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.usage'))
@section('content')
<h4><i class="bi bi-bar-chart me-1"></i>{{ __('super-ai-core::messages.usage') }} <small class="text-muted">(last {{ $days }} days)</small></h4>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h3 mb-0">{{ $summary['total_runs'] }}</div>
                <div class="small text-muted">Runs</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h3 mb-0">${{ number_format($summary["total_cost"], 2) }}</div>
                <div class="small text-muted">Total cost</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h3 mb-0">{{ number_format($summary['total_input_tokens']) }}</div>
                <div class="small text-muted">Input tokens</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h3 mb-0">{{ number_format($summary['total_output_tokens']) }}</div>
                <div class="small text-muted">Output tokens</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Model</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Model</th><th class="text-end">Runs</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                @foreach($byModel as $model => $d)
                    <tr><td class="small font-monospace">{{ $model }}</td><td class="text-end">{{ $d['runs'] }}</td><td class="text-end">${{ number_format($d["cost"], 2) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Backend</div>
            <div class="card-body p-0">
                <table class="table mb-0"><thead><tr><th>Backend</th><th class="text-end">Runs</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                @foreach($byBackend as $be => $d)
                    <tr><td>{{ $be }}</td><td class="text-end">{{ $d['runs'] }}</td><td class="text-end">${{ number_format($d["cost"], 2) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
    </div>
</div>
@endsection
