@extends(config('super-ai-core.layout', 'super-ai-core::layouts.app'))

@section('title', __('processes.title'))

@push('styles')
<style>
    .log-viewer {
        background: #1e1e2e;
        color: #cdd6f4;
        font-family: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace;
        font-size: .75rem;
        line-height: 1.6;
        padding: 1rem;
        border-radius: var(--tf-radius-sm, .375rem);
        max-height: 500px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-break: break-all;
    }
    .log-viewer::-webkit-scrollbar { width: 8px; }
    .log-viewer::-webkit-scrollbar-thumb { background: #45475a; border-radius: 4px; }
    .log-viewer::-webkit-scrollbar-track { background: #1e1e2e; }
    .log-viewer .log-empty { color: #6c7086; font-style: italic; }

    .status-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
    }
    .status-dot.running { background: var(--tf-success, #10b981); animation: pulse 1.5s infinite; }
    .status-dot.zombie  { background: var(--tf-warning, #f59e0b); }
    .status-dot.orphan  { background: var(--tf-danger, #ef4444); }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: .4; }
    }

    .process-row { cursor: pointer; transition: var(--tf-transition, all .15s ease); }
    .process-row:hover { background: var(--tf-primary-light, #eff6ff) !important; }
    .process-row.active { background: rgba(var(--tf-primary-rgb, 13, 110, 253), .08) !important; border-left: 3px solid var(--tf-primary, #0d6efd); }

    .log-panel { position: sticky; top: 80px; }
    .log-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .5rem .75rem;
        background: #313244;
        border-radius: var(--tf-radius-sm, .375rem) var(--tf-radius-sm, .375rem) 0 0;
        color: #cdd6f4;
        font-size: .8125rem;
    }
    .log-toolbar .log-title { font-weight: 600; }
    .log-toolbar .log-actions { display: flex; gap: .5rem; }
    .log-toolbar .btn-log {
        background: rgba(255,255,255,.1);
        border: none;
        color: #cdd6f4;
        padding: .25rem .5rem;
        border-radius: 4px;
        font-size: .75rem;
        cursor: pointer;
        transition: var(--tf-transition, all .15s ease);
    }
    .log-toolbar .btn-log:hover { background: rgba(255,255,255,.2); }
    .log-toolbar .btn-log.active { background: var(--tf-primary, #0d6efd); color: #fff; }

    .log-viewer.with-toolbar { border-radius: 0 0 var(--tf-radius-sm, .375rem) var(--tf-radius-sm, .375rem); }

    .stats-bar { display: flex; gap: 1.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .stat-item { display: flex; align-items: center; gap: .5rem; font-size: .875rem; font-weight: 500; }
    .stat-count { font-size: 1.25rem; font-weight: 700; }
</style>
@endpush

@section('content')
<div class="tf-page-header d-flex justify-content-between align-items-center">
    <h1><i class="bi bi-activity"></i> {{ __('processes.title') }}</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary btn-sm" onclick="refreshAll()">
            <i class="bi bi-arrow-clockwise"></i> {{ __('processes.refresh') }}
        </button>
    </div>
</div>

@php
    $running = collect($processes)->where('status', 'running')->count();
    $zombie  = collect($processes)->where('status', 'zombie')->count();
    $orphan  = collect($processes)->where('status', 'orphan')->count();
    $total   = count($processes);
@endphp
<div class="stats-bar">
    <div class="stat-item">
        <span class="stat-count" style="color:var(--tf-text, #0f172a)">{{ $total }}</span>
        {{ __('processes.total') }}
    </div>
    <div class="stat-item">
        <span class="status-dot running"></span>
        <span class="stat-count" style="color:var(--tf-success, #10b981)">{{ $running }}</span>
        {{ __('processes.status_running') }}
    </div>
    @if($zombie > 0)
    <div class="stat-item">
        <span class="status-dot zombie"></span>
        <span class="stat-count" style="color:var(--tf-warning, #f59e0b)">{{ $zombie }}</span>
        {{ __('processes.status_zombie') }}
    </div>
    @endif
    @if($orphan > 0)
    <div class="stat-item">
        <span class="status-dot orphan"></span>
        <span class="stat-count" style="color:var(--tf-danger, #ef4444)">{{ $orphan }}</span>
        {{ __('processes.status_orphan') }}
    </div>
    @endif
</div>

<div class="row">
    {{-- Process List --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:40%">{{ __('processes.task') }}</th>
                            <th>{{ __('processes.status') }}</th>
                            <th>{{ __('processes.duration') }}</th>
                            <th style="width:80px">{{ __('tasks.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($processes as $i => $proc)
                        <tr class="process-row" data-index="{{ $i }}" onclick="selectProcess({{ $i }})">
                            <td>
                                @if($proc->task_id)
                                    <div style="font-weight:600;font-size:.8125rem;line-height:1.3">{{ $proc->task_name }}</div>
                                    <div style="font-size:.7rem;color:var(--tf-text-muted, #64748b);margin-top:.125rem">
                                        <span>{{ $proc->project_name }}</span>
                                        <span class="mx-1">&middot;</span>
                                        <span>{{ $proc->task_type }}</span>
                                    </div>
                                    <div style="font-size:.7rem;margin-top:.125rem" class="d-flex align-items-center gap-1 flex-wrap">
                                        <span class="badge" style="background:#f1f5f9;color:#475569;font-size:.6rem">Run #{{ $proc->result_id }}</span>
                                        @if(!empty($proc->run_mode))
                                            <span class="badge" style="background:#fefce8;color:#a16207;font-size:.6rem">{{ $proc->run_mode }}</span>
                                        @endif
                                        @if($proc->is_scheduled)
                                            <span class="badge" style="background:#faf5ff;color:#7c3aed;font-size:.6rem"><i class="bi bi-clock"></i> {{ __('tasks.scheduled_run') }}</span>
                                        @endif
                                        @if($proc->language)
                                            <span class="badge" style="background:#f0f9ff;color:#0369a1;font-size:.6rem">{{ class_exists(\App\Models\Task::class) && !empty(\App\Models\Task::LANGUAGES[$proc->language]) ? \App\Models\Task::LANGUAGES[$proc->language] : $proc->language }}</span>
                                        @endif
                                        @if($proc->provider_name && $proc->provider_type !== 'builtin')
                                            <span class="badge" style="background:#ecfdf5;color:#047857;font-size:.6rem">{{ $proc->provider_name }}</span>
                                        @else
                                            <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:.6rem">{{ __('integrations.ai_provider_builtin_short') }}</span>
                                        @endif
                                        @if($proc->resolved_model)
                                            <span class="badge" style="background:#faf5ff;color:#7c3aed;font-size:.6rem">{{ $proc->resolved_model }}</span>
                                        @endif
                                        @if($proc->user && $proc->user !== '-')
                                            <span style="font-size:.65rem;color:var(--tf-text-muted, #64748b)">{{ $proc->user }}</span>
                                        @endif
                                    </div>
                                @else
                                    <div style="font-weight:600;font-size:.8125rem">
                                        <span>{{ $proc->external_label ?? $proc->backend }}</span>
                                    </div>
                                    @if($proc->external_id)
                                        <div style="font-size:.7rem;color:var(--tf-text-muted, #64748b)">#{{ $proc->external_id }}</div>
                                    @endif
                                    @if($proc->pid)
                                        <div style="font-size:.7rem;color:var(--tf-text-muted, #64748b)">PID: {{ $proc->pid }}</div>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <span class="status-dot {{ $proc->status }}"></span>
                                @if($proc->status === 'running')
                                    <span class="badge bg-success" style="font-size:.7rem">{{ __('processes.status_running') }}</span>
                                @elseif($proc->status === 'zombie')
                                    <span class="badge bg-warning text-dark" style="font-size:.7rem">{{ __('processes.status_zombie') }}</span>
                                @else
                                    <span class="badge bg-secondary" style="font-size:.7rem">{{ $proc->status }}</span>
                                @endif
                                @if($proc->pid)
                                    <div style="font-size:.7rem;color:var(--tf-text-muted, #64748b)">PID {{ $proc->pid }}</div>
                                @endif
                            </td>
                            <td><span style="font-size:.8125rem">{{ $proc->duration ?? ($proc->started_at?->diffForHumans(null, true) ?? '-') }}</span></td>
                            <td>
                                <button class="btn btn-outline-danger btn-sm"
                                        onclick="event.stopPropagation();killProcess('{{ $proc->id }}', {{ $proc->pid ?? 'null' }}, this)"
                                        title="{{ __('processes.kill') }}">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4">
                                <div class="tf-empty text-center text-muted py-4">
                                    <div class="tf-empty-icon"><i class="bi bi-check-circle fs-1"></i></div>
                                    <p class="mb-0">{{ __('processes.no_processes') }}</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Log Panel --}}
    <div class="col-lg-7">
        <div class="log-panel">
            <div class="card" id="logCard" style="display:{{ $total > 0 ? 'block' : 'none' }}">
                <div class="log-toolbar">
                    <span class="log-title" id="logTitle">{{ __('processes.select_process') }}</span>
                    <div class="log-actions">
                        <button class="btn-log" id="btnAutoScroll" onclick="toggleAutoScroll()" title="{{ __('processes.auto_scroll') }}">
                            <i class="bi bi-arrow-down-circle"></i> Auto
                        </button>
                        <button class="btn-log" id="btnLiveFollow" onclick="toggleLiveFollow()" title="{{ __('processes.live_follow') }}">
                            <i class="bi bi-broadcast"></i> Live
                        </button>
                        <button class="btn-log" onclick="copyLog()" title="{{ __('processes.copy_log') }}">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
                <div class="log-viewer with-toolbar" id="logContent">
                    <span class="log-empty">{{ __('processes.click_to_view') }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    $processesJs = [];
    foreach ($processes as $p) {
        $processesJs[] = [
            'id'        => $p->id,
            'pid'       => $p->pid,
            'status'    => $p->status,
            'task_id'   => $p->task_id,
            'task_name' => $p->task_name ?? $p->external_label ?? $p->backend,
            'result_id' => $p->result_id,
            'log_file'  => $p->log_file,
        ];
    }
@endphp
<script>
const processes = @json($processesJs);

let selectedIndex = null;
let autoScroll = true;
let liveFollow = false;
let liveInterval = null;
let logFetchTimer = null;

function selectProcess(index) {
    selectedIndex = index;
    const proc = processes[index];

    document.querySelectorAll('.process-row').forEach(r => r.classList.remove('active'));
    document.querySelector(`.process-row[data-index="${index}"]`)?.classList.add('active');

    document.getElementById('logCard').style.display = 'block';
    document.getElementById('logTitle').textContent = (proc.task_name || '') + (proc.pid ? ` (PID ${proc.pid})` : '');

    loadLog(proc.id);

    if (liveFollow) startLiveFollow();
}

function loadLog(id) {
    if (!id) {
        document.getElementById('logContent').innerHTML = '<span class="log-empty">{{ __("processes.no_log_file") }}</span>';
        return;
    }

    document.getElementById('logContent').innerHTML = '<span class="log-empty">{{ __("processes.loading") }}</span>';

    fetch('{{ url(config("super-ai-core.route.prefix", "super-ai-core") . "/processes") }}/' + encodeURIComponent(id) + '/log', {
        headers: { Accept: 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('logContent');
        if (data.ok && data.text) {
            el.textContent = data.text;
            if (autoScroll) el.scrollTop = el.scrollHeight;
        } else if (data.ok) {
            el.innerHTML = '<span class="log-empty">{{ __("processes.log_empty") }}</span>';
        } else {
            el.innerHTML = '<span class="log-empty">' + (data.error || '{{ __("processes.log_error") }}') + '</span>';
        }
    })
    .catch(() => {
        document.getElementById('logContent').innerHTML = '<span class="log-empty">{{ __("processes.log_error") }}</span>';
    });
}

function toggleAutoScroll() {
    autoScroll = !autoScroll;
    document.getElementById('btnAutoScroll').classList.toggle('active', autoScroll);
}

function toggleLiveFollow() {
    liveFollow = !liveFollow;
    document.getElementById('btnLiveFollow').classList.toggle('active', liveFollow);
    if (liveFollow) startLiveFollow(); else stopLiveFollow();
}

function startLiveFollow() {
    stopLiveFollow();
    if (selectedIndex === null) return;
    const proc = processes[selectedIndex];
    liveInterval = setInterval(() => loadLog(proc.id), 2500);
}

function stopLiveFollow() {
    if (liveInterval) { clearInterval(liveInterval); liveInterval = null; }
}

function copyLog() {
    const el = document.getElementById('logContent');
    navigator.clipboard.writeText(el.textContent).then(() => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({toast:true, position:'top-end', icon:'success', title:'{{ __("processes.copied") }}', showConfirmButton:false, timer:1500});
        }
    });
}

function killProcess(id, pid, btn) {
    const confirmText = pid ? '{{ __("processes.kill_confirm_text") }}'.replace(':pid', pid) : '{{ __("processes.kill_confirm_db") }}';
    const run = () => {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        fetch('{{ route("super-ai-core.processes.kill") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ id })
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({toast:true, position:'top-end', icon:'success', title:'{{ __("processes.killed_db_updated") }}', showConfirmButton:false, timer:2000});
                }
                setTimeout(() => location.reload(), 800);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-x-circle"></i>';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({toast:true, position:'top-end', icon:'error', title: data.error || '{{ __("processes.log_error") }}', showConfirmButton:false, timer:2500});
                }
            }
        });
    };

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: '{{ __("processes.kill_confirm_title") }}',
            text: confirmText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: '{{ __("processes.kill") }}',
            cancelButtonText: '{{ __("tasks.cancel") }}',
            reverseButtons: true
        }).then(r => { if (r.isConfirmed) run(); });
    } else {
        if (confirm(confirmText)) run();
    }
}

function refreshAll() { location.reload(); }

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('btnAutoScroll')?.classList.add('active');
    if (processes.length > 0) {
        selectProcess(0);
        liveFollow = true;
        document.getElementById('btnLiveFollow')?.classList.add('active');
        startLiveFollow();
    }
});
</script>
@endpush
