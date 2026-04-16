@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.integrations'))
@section('content')
<h4><i class="bi bi-plug me-1"></i>{{ __('super-ai-core::messages.integrations') }}</h4>

@php
    $grouped = collect($registry)->groupBy('category', true);
@endphp

@foreach($categories as $catKey => $catMeta)
    @if(!$grouped->has($catKey)) @continue @endif
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <strong><i class="bi {{ $catMeta['icon'] ?? 'bi-folder' }} me-1"></i>{{ ucfirst($catKey) }}</strong>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 30px;"></th>
                        <th>Server</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($grouped[$catKey] as $key => $def)
                    @php $st = $statuses[$key] ?? []; @endphp
                    <tr>
                        <td><i class="bi {{ $def['icon'] ?? 'bi-box' }}" style="color: {{ $def['color'] ?? '#666' }};"></i></td>
                        <td>
                            <div class="fw-semibold">{{ $def['name'] ?? $key }}</div>
                            <code class="small text-muted">{{ $key }}</code>
                        </td>
                        <td><span class="badge bg-light text-dark border">{{ $def['type'] ?? '—' }}</span></td>
                        <td>
                            @if(!empty($st['installed']))
                                <span class="badge bg-success">installed</span>
                            @elseif(!empty($st['configured']))
                                <span class="badge bg-info">configured</span>
                            @else
                                <span class="badge bg-secondary">not installed</span>
                            @endif
                        </td>
                        <td>
                            @if(empty($st['installed']))
                                <button class="btn btn-outline-primary btn-sm" onclick="installMcp('{{ $key }}')">Install</button>
                            @else
                                <button class="btn btn-outline-secondary btn-sm" onclick="testMcp('{{ $key }}')">Test</button>
                                <button class="btn btn-outline-danger btn-sm" onclick="uninstallMcp('{{ $key }}')">Uninstall</button>
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

function installMcp(key) {
    fetch('{{ url("super-ai-core/integrations") }}/' + key + '/install', {method: 'POST', headers: hdr(), body: '{}'})
        .then(r => r.json()).then(d => { alert(JSON.stringify(d)); location.reload(); });
}
function uninstallMcp(key) {
    if (!confirm('Uninstall ' + key + '?')) return;
    fetch('{{ url("super-ai-core/integrations") }}/' + key + '/uninstall', {method: 'POST', headers: hdr(), body: '{}'})
        .then(r => r.json()).then(d => { alert(JSON.stringify(d)); location.reload(); });
}
function testMcp(key) {
    fetch('{{ url("super-ai-core/integrations") }}/' + key + '/test', {headers: {Accept: 'application/json'}})
        .then(r => r.json()).then(d => alert(JSON.stringify(d)));
}
</script>
@endsection
