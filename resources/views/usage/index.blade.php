@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.usage'))
@section('content')
<h4><i class="bi bi-bar-chart me-1"></i>{{ __('super-ai-core::messages.usage') }} <small class="text-muted">(last {{ $days }} days)</small></h4>

<form method="GET" class="row g-2 align-items-end mb-3 small">
    <input type="hidden" name="filters_applied" value="1">
    <div class="col-auto">
        <label class="form-label mb-0">Days</label>
        <input type="number" name="days" class="form-control form-control-sm" style="width: 90px" value="{{ $days }}" min="1" max="365">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Backend</label>
        <select name="backend" class="form-select form-select-sm">
            <option value="">all</option>
            @foreach($allBackends as $b)
                <option value="{{ $b }}" @selected(($filters['backend'] ?? '') === $b)>{{ $b }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Task type</label>
        <select name="task_type" class="form-select form-select-sm">
            <option value="">all</option>
            @foreach($allTaskTypes as $t)
                @if ($t)
                    <option value="{{ $t }}" @selected(($filters['task_type'] ?? '') === $t)>{{ $t }}</option>
                @endif
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Model</label>
        <select name="model" class="form-select form-select-sm">
            <option value="">all</option>
            @foreach($allModels as $m)
                <option value="{{ $m }}" @selected(($filters['model'] ?? '') === $m)>{{ $m }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto form-check form-switch">
        <input class="form-check-input" type="checkbox" name="hide_empty" value="1" id="hideEmpty" @checked($hideEmpty)>
        <label class="form-check-label" for="hideEmpty">Hide 0-token rows</label>
    </div>
    <div class="col-auto form-check form-switch">
        <input class="form-check-input" type="checkbox" name="hide_tests" value="1" id="hideTests" @checked($hideTests)>
        <label class="form-check-label" for="hideTests">Hide test_connection</label>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-2">
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
                <div class="h3 mb-0">${{ number_format($summary['total_cost'], 4) }}</div>
                <div class="small text-muted">Billed cost</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm" title="Pay-as-you-go equivalent for subscription engines">
            <div class="card-body text-center">
                <div class="h3 mb-0 text-info">~${{ number_format($summary['total_shadow_cost'], 4) }}</div>
                <div class="small text-muted">Shadow cost (est.)</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h5 mb-0">{{ number_format($summary['total_input_tokens']) }}</div>
                <div class="small text-muted">Input tokens</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="h5 mb-0">{{ number_format($summary['total_output_tokens']) }}</div>
                <div class="small text-muted">Output tokens</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Task Type</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Task type</th><th class="text-end">Runs</th><th class="text-end">Cost</th><th class="text-end">Shadow</th></tr></thead>
                    <tbody>
                    @forelse($byTaskType as $tt => $d)
                        <tr>
                            <td class="small"><code>{{ $tt }}</code></td>
                            <td class="text-end">{{ $d['runs'] }}</td>
                            <td class="text-end">${{ number_format($d['cost'], 4) }}</td>
                            <td class="text-end text-info">${{ number_format($d['shadow_cost'], 4) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted small py-3">No tagged task types yet — callers should pass <code>task_type</code> when invoking the Dispatcher / UsageRecorder.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Model</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Model</th><th class="text-end">Runs</th><th class="text-end">Cost</th><th class="text-end">Shadow</th></tr></thead>
                    <tbody>
                    @foreach($byModel as $model => $d)
                        <tr>
                            <td class="small font-monospace">{{ $model }}</td>
                            <td class="text-end">{{ $d['runs'] }}</td>
                            <td class="text-end">${{ number_format($d['cost'], 4) }}</td>
                            <td class="text-end text-info">${{ number_format($d['shadow_cost'], 4) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">By Backend</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Backend</th><th class="text-end">Runs</th><th class="text-end">Cost</th><th class="text-end">Shadow</th></tr></thead>
                    <tbody>
                    @foreach($byBackend as $be => $d)
                        <tr>
                            <td>{{ $be }}</td>
                            <td class="text-end">{{ $d['runs'] }}</td>
                            <td class="text-end">${{ number_format($d['cost'], 4) }}</td>
                            <td class="text-end text-info">${{ number_format($d['shadow_cost'], 4) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        Recent calls
        <small class="text-muted ms-2">showing {{ $logs->count() }} of max 500</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>When</th>
                    <th>Task type</th>
                    <th>Capability</th>
                    <th>Backend</th>
                    <th>Model</th>
                    <th>Provider / Service</th>
                    <th>Billing</th>
                    <th class="text-end">Input</th>
                    <th class="text-end">Output</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Shadow</th>
                    <th class="text-end">Duration</th>
                </tr>
            </thead>
            <tbody>
            @foreach($logs as $row)
                <tr>
                    <td class="text-muted" style="white-space: nowrap">{{ $row->created_at?->diffForHumans() }}</td>
                    <td><code>{{ $row->task_type ?? '—' }}</code></td>
                    <td class="text-muted small">{{ $row->capability ?? '—' }}</td>
                    <td>{{ $row->backend }}</td>
                    <td class="font-monospace">{{ $row->model }}</td>
                    <td class="small">
                        @if ($row->provider_id)
                            <span class="badge bg-secondary-subtle text-secondary" title="provider #{{ $row->provider_id }}">
                                {{ ($providers[$row->provider_id] ?? ('#' . $row->provider_id)) }}
                            </span>
                        @elseif ($row->service_id)
                            <span class="badge bg-light text-dark" title="service #{{ $row->service_id }}">
                                svc: {{ ($services[$row->service_id] ?? ('#' . $row->service_id)) }}
                            </span>
                        @else
                            <span class="text-muted">builtin</span>
                        @endif
                    </td>
                    <td>
                        @if ($row->billing_model === 'subscription')
                            <span class="badge bg-info-subtle text-info">sub</span>
                        @elseif ($row->billing_model === 'usage')
                            <span class="badge bg-success-subtle text-success">usage</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end">{{ number_format($row->input_tokens) }}</td>
                    <td class="text-end">{{ number_format($row->output_tokens) }}</td>
                    <td class="text-end">${{ number_format((float) $row->cost_usd, 6) }}</td>
                    <td class="text-end text-info">{{ $row->shadow_cost_usd !== null ? '$' . number_format((float) $row->shadow_cost_usd, 6) : '—' }}</td>
                    <td class="text-end text-muted">{{ $row->duration_ms !== null ? $row->duration_ms . 'ms' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
