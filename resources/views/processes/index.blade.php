@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.processes'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-cpu me-1"></i>{{ __('super-ai-core::messages.processes') }}</h4>
    <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
        <i class="bi bi-arrow-clockwise"></i>
    </button>
</div>

<div class="row g-0 border rounded" style="height: calc(100vh - 220px); overflow: hidden;">
    {{-- Left: process list --}}
    <div class="col-md-5 border-end" style="overflow-y: auto; height: 100%;">
        <div class="list-group list-group-flush">
            @forelse($processes as $p)
                <button type="button" class="list-group-item list-group-item-action process-row"
                        data-id="{{ $p->id }}"
                        data-pid="{{ $p->pid }}"
                        data-status="{{ $p->status }}">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="badge bg-{{ $p->status === 'running' ? 'success' : ($p->status === 'killed' ? 'danger' : 'secondary') }} me-1">{{ $p->status }}</span>
                            <span class="badge bg-light text-dark border">{{ $p->backend }}</span>
                            <span class="fw-semibold ms-1">PID {{ $p->pid }}</span>
                        </div>
                        <small class="text-muted">{{ $p->started_at?->diffForHumans() ?? '—' }}</small>
                    </div>
                    @if($p->external_label)
                        <div class="small mt-1">{{ $p->external_label }}</div>
                    @endif
                    @if($p->external_id)
                        <div class="small text-muted">#{{ $p->external_id }}</div>
                    @endif
                    @if($p->command)
                        <div class="small text-muted font-monospace text-truncate" title="{{ $p->command }}">{{ $p->command }}</div>
                    @endif
                </button>
            @empty
                <div class="p-4 text-center text-muted small">{{ __('super-ai-core::messages.no_processes') }}</div>
            @endforelse
        </div>
    </div>

    {{-- Right: log viewer --}}
    <div class="col-md-7 d-flex flex-column" style="height: 100%;">
        <div class="d-flex justify-content-between align-items-center p-2 border-bottom bg-light">
            <div id="log-header" class="small text-muted">{{ __('super-ai-core::messages.select_process') }}</div>
            <div>
                <button type="button" class="btn btn-sm btn-outline-danger" id="btn-kill" disabled>
                    <i class="bi bi-stop-circle"></i> {{ __('super-ai-core::messages.kill') }}
                </button>
                <label class="small ms-2">
                    <input type="checkbox" id="auto-refresh" checked> {{ __('super-ai-core::messages.auto_refresh') }}
                </label>
            </div>
        </div>
        <pre id="log-pane" class="mb-0 p-3 flex-grow-1" style="overflow-y: auto; font-size: 12px; background: #1e1e1e; color: #d4d4d4; white-space: pre-wrap;">{{ __('super-ai-core::messages.select_process') }}</pre>
    </div>
</div>

<script>
(function() {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let currentId = null;
    let timer = null;

    document.querySelectorAll('.process-row').forEach(row => {
        row.addEventListener('click', () => {
            document.querySelectorAll('.process-row').forEach(r => r.classList.remove('active'));
            row.classList.add('active');
            currentId = row.dataset.id;
            document.getElementById('btn-kill').disabled = row.dataset.status !== 'running';
            loadLog();
            restartTimer();
        });
    });

    function loadLog() {
        if (!currentId) return;
        fetch('{{ url("super-ai-core/processes") }}/' + currentId + '/log', {
            headers: {Accept: 'application/json'}
        }).then(r => r.json()).then(d => {
            const pane = document.getElementById('log-pane');
            if (!d.ok) {
                pane.textContent = d.error || '(no log)';
                return;
            }
            pane.textContent = d.text || '(empty)';
            pane.scrollTop = pane.scrollHeight;
            const header = document.getElementById('log-header');
            header.textContent = (d.path || '') + ' · ' + (d.size ?? 0) + ' bytes · ' + (d.is_alive ? 'alive' : 'not alive');
        });
    }

    function restartTimer() {
        if (timer) clearInterval(timer);
        if (document.getElementById('auto-refresh').checked && currentId) {
            timer = setInterval(loadLog, 3000);
        }
    }
    document.getElementById('auto-refresh').addEventListener('change', restartTimer);

    document.getElementById('btn-kill').addEventListener('click', () => {
        if (!currentId || !confirm('Kill this process?')) return;
        fetch('{{ route("super-ai-core.processes.kill") }}', {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json'},
            body: JSON.stringify({id: currentId})
        }).then(r => r.json()).then(() => location.reload());
    });
})();
</script>
@endsection