@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.integrations'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-plug me-1"></i>{{ __('super-ai-core::messages.integrations') }}</h4>
    <button class="btn btn-primary btn-sm" id="btn-install-all">
        <i class="bi bi-download me-1"></i>{{ __('super-ai-core::messages.install_all') }}
    </button>
</div>

@php
    $grouped = collect($registry)->groupBy('category', true);
    // Translation helper: try category-specific key first, fall back to display
    $catLabel = function ($key) {
        $transKey = "super-ai-core::integrations.cat_{$key}";
        $trans = __($transKey);
        return $trans === $transKey ? ucfirst($key) : $trans;
    };
@endphp

@foreach($categories as $catKey => $catMeta)
    @if(!$grouped->has($catKey)) @continue @endif
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <strong><i class="bi {{ $catMeta['icon'] ?? 'bi-folder' }} me-1"></i>{{ $catLabel($catKey) }}</strong>
            <span class="badge bg-light text-dark border ms-2">{{ $grouped[$catKey]->count() }}</span>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 30px;"></th>
                        <th>{{ __('super-ai-core::integrations.server') ?: 'Server' }}</th>
                        <th>{{ __('super-ai-core::messages.type') }}</th>
                        <th>{{ __('super-ai-core::messages.is_active') }}</th>
                        <th>{{ __('super-ai-core::messages.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($grouped[$catKey] as $key => $def)
                    @php $st = $statuses[$key] ?? []; @endphp
                    <tr data-server-key="{{ $key }}">
                        <td><i class="bi {{ $def['icon'] ?? 'bi-box' }}" style="color: {{ $def['color'] ?? '#666' }};"></i></td>
                        <td>
                            <div class="fw-semibold">{{ $def['name'] ?? $key }}</div>
                            <code class="small text-muted">{{ $key }}</code>
                        </td>
                        <td><span class="badge bg-light text-dark border">{{ $def['type'] ?? '—' }}</span></td>
                        <td>
                            @if(!empty($st['installed']))
                                <span class="badge bg-success">{{ __('super-ai-core::messages.cli_installed') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ __('super-ai-core::messages.cli_not_installed') }}</span>
                            @endif
                        </td>
                        <td>
                            @if(empty($st['installed']))
                                <button class="btn btn-outline-primary btn-sm btn-install" data-key="{{ $key }}">
                                    <i class="bi bi-download"></i> {{ __('super-ai-core::integrations.install') ?: 'Install' }}
                                </button>
                            @else
                                <button class="btn btn-outline-secondary btn-sm" onclick="testMcp('{{ $key }}')">
                                    <i class="bi bi-wifi"></i> {{ __('super-ai-core::messages.test_connection') }}
                                </button>
                                <button class="btn btn-outline-danger btn-sm" onclick="uninstallMcp('{{ $key }}')">
                                    <i class="bi bi-trash"></i> {{ __('super-ai-core::integrations.uninstall') ?: 'Uninstall' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
function hdr() { return {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json'}; }

function installMcp(key, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
    return fetch('{{ url("super-ai-core/integrations") }}/' + key + '/install', {method: 'POST', headers: hdr(), body: '{}'})
        .then(r => r.json());
}
function uninstallMcp(key) {
    if (!confirm('Uninstall ' + key + '?')) return;
    fetch('{{ url("super-ai-core/integrations") }}/' + key + '/uninstall', {method: 'POST', headers: hdr(), body: '{}'})
        .then(r => r.json()).then(() => location.reload());
}
function testMcp(key) {
    fetch('{{ url("super-ai-core/integrations") }}/' + key + '/test', {headers: {Accept: 'application/json'}})
        .then(r => r.json()).then(d => alert((d.success ? '✓ ' : '✗ ') + (d.message ?? JSON.stringify(d))));
}

document.querySelectorAll('.btn-install').forEach(b => {
    b.addEventListener('click', () => installMcp(b.dataset.key, b).then(() => location.reload()));
});

// Install all (parallel, with progress + reload at end)
document.getElementById('btn-install-all').addEventListener('click', async () => {
    const pending = [...document.querySelectorAll('.btn-install')];
    if (!pending.length) { alert('Nothing to install.'); return; }
    if (!confirm('Install ' + pending.length + ' servers?')) return;
    const btn = document.getElementById('btn-install-all');
    btn.disabled = true;
    let done = 0;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${done}/${pending.length}`;
    await Promise.all(pending.map(b => installMcp(b.dataset.key, b).then(() => {
        done++;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${done}/${pending.length}`;
    }).catch(() => { done++; })));
    location.reload();
});
</script>
@endsection
