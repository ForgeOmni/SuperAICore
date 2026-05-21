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
    <div class="col-auto form-check form-switch" title="Show only rows the dispatcher flagged as cache-cold (Anthropic 5-min TTL heuristic)">
        <input class="form-check-input" type="checkbox" name="cold_only" value="1" id="coldOnly" @checked($coldOnly)>
        <label class="form-check-label" for="coldOnly">Cache-cold only</label>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    </div>
</form>

@if(($summary['total_cache_cold'] ?? 0) > 0 && !$coldOnly)
    <div class="alert alert-warning py-2 px-3 small d-flex align-items-center">
        <i class="bi bi-snow me-2"></i>
        <span>
            <strong>{{ $summary['total_cache_cold'] }}</strong> of {{ $summary['total_runs'] }} recent calls hit a cold prompt cache
            (5-min TTL elapsed). Re-running them inside the window would cut input cost.
        </span>
        <a class="ms-auto small" href="{{ request()->fullUrlWithQuery(['cold_only' => 1, 'filters_applied' => 1]) }}">show only those →</a>
    </div>
@endif

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
    <div class="col-lg-3 col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span>By Source</span>
                @if(!empty($summary['total_ambient_runs']))
                    <span class="badge bg-secondary" title="Background dedup / staleness ticks. SuperAgent's AmbientWorker tags these so user-facing spend stays comparable across periods.">
                        <i class="bi bi-moon-stars"></i>
                        {{ $summary['total_ambient_runs'] }} ambient · ${{ number_format($summary['total_ambient_cost'], 4) }}
                    </span>
                @endif
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Source</th><th class="text-end">Runs</th><th class="text-end">Cost</th><th class="text-end">Shadow</th></tr></thead>
                    <tbody>
                    @forelse($bySource as $src => $d)
                        <tr>
                            <td class="small">
                                @if($src === 'ambient')
                                    <span class="badge bg-secondary"><i class="bi bi-moon-stars"></i> ambient</span>
                                @elseif($src === 'user')
                                    <span class="badge bg-primary"><i class="bi bi-person"></i> user</span>
                                @else
                                    <code>{{ $src }}</code>
                                @endif
                            </td>
                            <td class="text-end">{{ $d['runs'] }}</td>
                            <td class="text-end">${{ number_format($d['cost'], 4) }}</td>
                            <td class="text-end text-info">${{ number_format($d['shadow_cost'], 4) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted small py-3">No source-tagged rows yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
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
    <div class="col-lg-3 col-md-6">
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
    <div class="col-lg-3 col-md-6">
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
                    <td class="font-monospace">
                        {{ $row->model }}
                        @php
                            $cacheWarn = ($row->metadata ?? [])['cache_warning'] ?? null;
                        @endphp
                        @if($cacheWarn)
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1"
                                  title="{{ $cacheWarn }}">
                                <i class="bi bi-snow"></i> cold
                            </span>
                        @endif
                    </td>
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
                    <td class="text-end text-muted">
                        {{ $row->duration_ms !== null ? $row->duration_ms . 'ms' : '—' }}
                        @php
                            $__diff = $row->file_diff_summary ?? null;
                        @endphp
                        @if(is_array($__diff) && (($__diff['files'] ?? 0) > 0))
                            @php
                                $__diffPanelPayload = [
                                    'title'   => 'File diff — usage #' . $row->id,
                                    'type'    => 'json',
                                    'content' => json_encode($__diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ];
                            @endphp
                            <a href="#" class="ms-1 text-decoration-none"
                               title="{{ $__diff['files'] }} file(s) changed; +{{ $__diff['additions'] ?? 0 }} −{{ $__diff['deletions'] ?? 0 }}"
                               data-side-panel-trigger='@json($__diffPanelPayload)'>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">+{{ $__diff['additions'] ?? 0 }}</span><span
                                      class="badge bg-danger-subtle text-danger border border-danger-subtle">−{{ $__diff['deletions'] ?? 0 }}</span>
                            </a>
                        @endif
                        @if($row->pre_snapshot && (bool) (config('super-ai-core.snapshot.revert_enabled') ?? true))
                            <a href="#" class="ms-1 text-decoration-none text-warning"
                               title="Revert worktree to pre-dispatch snapshot {{ substr($row->pre_snapshot, 0, 7) }}"
                               onclick="event.preventDefault(); if(confirm('Revert worktree to pre-dispatch snapshot {{ substr($row->pre_snapshot, 0, 7) }}?\n\nTracked files will be restored; untracked files left in place.')) { fetch('{{ route('super-ai-core.usage.revert', $row->id) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } }).then(r => r.json()).then(d => alert(d.message || (d.ok ? 'Reverted.' : 'Revert failed.'))); }">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>
                        @endif
                        @if(!empty($row->metadata))
                            @php
                                $__sidePanelPayload = [
                                    'title'   => 'Usage row #' . $row->id,
                                    'type'    => 'json',
                                    'content' => json_encode($row->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ];
                            @endphp
                            <a href="#" class="ms-1 text-decoration-none" title="Inspect metadata"
                               data-side-panel-trigger='@json($__sidePanelPayload)'>
                                <i class="bi bi-window-sidebar"></i>
                            </a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
