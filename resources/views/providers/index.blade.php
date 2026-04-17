@extends('super-ai-core::layouts.app')

@section('title', __('super-ai-core::messages.providers'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4><i class="bi bi-cpu me-1"></i>{{ __('super-ai-core::messages.providers') }}</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#new-provider-modal">
        <i class="bi bi-plus-lg"></i> {{ __('super-ai-core::messages.create_provider') }}
    </button>
</div>

{{-- CLI Status + Default Backend --}}
@php
    $backendLabels = array_intersect_key(
        [
            'claude'     => 'Claude Code CLI',
            'codex'      => 'Codex CLI',
            'gemini'     => 'Gemini CLI',
            'superagent' => 'SuperAgent SDK',
        ],
        array_flip($backends)
    );
@endphp
<div class="row g-3 mb-3">
    @foreach($backendLabels as $be => $label)
        @php
            $st = $cliStatuses[$be] ?? [];
            $isDisabled = !empty($backendDisabled[$be]);
        @endphp
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 {{ ($defaultBackend === $be) ? 'border-primary border-2' : '' }} {{ $isDisabled ? 'opacity-50' : '' }}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h6 class="mb-0">{{ $label }}</h6>
                            <code class="small text-muted">{{ $be }}</code>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            @if(!empty($st['installed']))
                                <span class="badge bg-success">{{ __('super-ai-core::messages.cli_installed') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ __('super-ai-core::messages.cli_not_installed') }}</span>
                            @endif
                            <form method="POST" action="{{ route('super-ai-core.providers.toggle-backend') }}" class="form-check form-switch m-0">
                                @csrf
                                <input type="hidden" name="backend" value="{{ $be }}">
                                <input type="hidden" name="enabled" value="{{ $isDisabled ? '1' : '0' }}">
                                <input type="checkbox" class="form-check-input" onchange="this.form.submit()" {{ $isDisabled ? '' : 'checked' }}>
                                <label class="form-check-label small">{{ $isDisabled ? __('super-ai-core::messages.engine_off') : __('super-ai-core::messages.engine_on') }}</label>
                            </form>
                        </div>
                    </div>
                    @if(!empty($st['installed']))
                        <div class="small text-muted">
                            @if(!empty($st['version']))
                                <div><i class="bi bi-tag me-1"></i>{{ __('super-ai-core::messages.cli_version') }}: <code>{{ $st['version'] }}</code></div>
                            @endif
                            @if(!empty($st['path']))
                                <div class="text-truncate" title="{{ $st['path'] }}"><i class="bi bi-folder me-1"></i>{{ $st['path'] }}</div>
                            @endif
                            @if(!empty($st['auth']))
                                @php $loggedIn = $st['auth']['loggedIn'] ?? !empty($st['auth']); @endphp
                                <div>
                                    <i class="bi bi-{{ $loggedIn ? 'check-circle text-success' : 'exclamation-circle text-warning' }} me-1"></i>
                                    {{ $loggedIn ? __('super-ai-core::messages.cli_auth_ok') : __('super-ai-core::messages.cli_auth_missing') }}
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="small text-muted">
                            @if($be === 'claude')
                                <code>npm i -g @anthropic-ai/claude-code</code>
                            @elseif($be === 'codex')
                                <code>brew install codex</code>
                            @elseif($be === 'gemini')
                                <code>npm i -g @google/gemini-cli</code>
                            @endif
                        </div>
                    @endif

                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Provider list grouped by backend, with built-in rows --}}
@foreach($providersByBackend as $be => $beProviders)
    @php
        $beLabel = [
            'claude'     => 'Claude Code',
            'codex'      => 'Codex',
            'gemini'     => 'Gemini',
            'superagent' => 'SuperAgent SDK',
        ][$be] ?? $be;
        $anyActive = $beProviders->contains(fn ($p) => $p->is_active);
        $beDisabled = !empty($backendDisabled[$be]);
    @endphp
    <div class="card border-0 shadow-sm mb-3 {{ $beDisabled ? 'opacity-50' : '' }}">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <strong><i class="bi bi-{{ ['claude'=>'robot','codex'=>'code-slash','gemini'=>'stars','superagent'=>'cpu'][$be] ?? 'plug' }} me-1"></i>{{ $beLabel }}</strong>
                <code class="small text-muted ms-2">{{ $be }}</code>
            </div>
            @if($beDisabled)
                <span class="badge bg-secondary"><i class="bi bi-power me-1"></i>{{ __('super-ai-core::messages.engine_disabled_badge') }}</span>
            @endif
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('super-ai-core::messages.name') }}</th>
                        <th>{{ __('super-ai-core::messages.type') }}</th>
                        <th>{{ __('super-ai-core::messages.is_active') }}</th>
                        <th>{{ __('super-ai-core::messages.api_key') }}</th>
                        <th>{{ __('super-ai-core::messages.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Built-in synthetic row (only for CLI backends that have a local login) --}}
                    @if($be !== 'superagent')
                        <tr class="table-light">
                            <td class="fw-semibold">
                                <i class="bi bi-box-seam me-1"></i>
                                {{ __('super-ai-core::messages.builtin') }}
                                <span class="badge bg-light text-dark border ms-1">{{ $beLabel }} local</span>
                            </td>
                            <td class="small text-muted">builtin</td>
                            <td>
                                @if(!$anyActive)
                                    <span class="badge bg-success">{{ __('super-ai-core::messages.default_backend') }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small text-muted">—</td>
                            <td>
                                @if(!$anyActive)
                                    <button type="button" class="btn btn-primary btn-sm" disabled>
                                        <i class="bi bi-check2"></i> {{ __('super-ai-core::messages.default_backend') }}
                                    </button>
                                @else
                                    <form method="POST" action="{{ route('super-ai-core.providers.activate-builtin') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="backend" value="{{ $be }}">
                                        <button class="btn btn-outline-success btn-sm">{{ __('super-ai-core::messages.use_builtin') }}</button>
                                    </form>
                                @endif
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="testBuiltin('{{ $be }}')">{{ __('super-ai-core::messages.test_connection') }}</button>
                            </td>
                        </tr>
                    @endif

                    {{-- External providers --}}
                    @forelse($beProviders as $p)
                        <tr>
                            <td class="fw-semibold">{{ $p->name }}</td>
                            <td class="small text-muted">{{ $p->type }}</td>
                            <td>
                                @if($p->is_active)
                                    <span class="badge bg-success">{{ __('super-ai-core::messages.default_backend') }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small font-monospace">{{ $p->masked_api_key ?? '—' }}</td>
                            <td>
                                @if(!$p->is_active)
                                    <form method="POST" action="{{ route('super-ai-core.providers.activate', $p) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-outline-success btn-sm">{{ __('super-ai-core::messages.set_default') }}</button>
                                    </form>
                                @endif
                                <button class="btn btn-outline-primary btn-sm" onclick="testProvider({{ $p->id }})">{{ __('super-ai-core::messages.test_connection') }}</button>
                                <form method="POST" action="{{ route('super-ai-core.providers.destroy', $p) }}" class="d-inline" onsubmit="return confirm('{{ __('super-ai-core::messages.delete') }}?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('super-ai-core::messages.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        @if($be === 'superagent')
                            <tr><td colspan="5" class="text-center text-muted py-3">{{ __('super-ai-core::messages.superagent_requires_provider') }}</td></tr>
                        @endif
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endforeach

{{-- Create modal --}}
<div class="modal fade" id="new-provider-modal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('super-ai-core.providers.store') }}">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">{{ __('super-ai-core::messages.create_provider') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label small">{{ __('super-ai-core::messages.name') }}</label>
                    <input type="text" name="name" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('super-ai-core::messages.backend') }}</label>
                    <select name="backend" id="new-provider-backend" class="form-select form-select-sm" required>
                        @foreach($backends as $be)
                            <option value="{{ $be }}">{{ $be }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('super-ai-core::messages.type') }}</label>
                    <select name="type" id="new-provider-type" class="form-select form-select-sm" required></select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('super-ai-core::messages.api_key') }}</label>
                    <input type="password" name="api_key" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('super-ai-core::messages.base_url') }}</label>
                    <input type="url" name="base_url" class="form-control form-control-sm" placeholder="https://api.anthropic.com">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">{{ __('super-ai-core::messages.cancel') }}</button>
                <button type="submit" class="btn btn-sm btn-primary">{{ __('super-ai-core::messages.submit') }}</button>
            </div>
        </form>
    </div>
</div>

<script>
// Narrow the "type" select in the New-provider modal to only the types
// that are valid for the currently chosen backend (same matrix SuperTeam used).
const BACKEND_TYPES = @json($backendTypes);
(function () {
    const backendEl = document.getElementById('new-provider-backend');
    const typeEl = document.getElementById('new-provider-type');
    if (!backendEl || !typeEl) return;

    function refreshTypes() {
        const allowed = BACKEND_TYPES[backendEl.value] || [];
        const prev = typeEl.value;
        typeEl.innerHTML = '';
        allowed.forEach(t => {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            typeEl.appendChild(opt);
        });
        if (allowed.includes(prev)) typeEl.value = prev;
    }
    backendEl.addEventListener('change', refreshTypes);
    refreshTypes();
})();

function testProvider(id) {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    fetch('{{ url("super-ai-core/providers") }}/' + id + '/test', {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}
    }).then(r => r.json()).then(d => {
        alert((d.success ? '✓ ' : '✗ ') + (d.message ?? 'Unknown'));
    });
}
function testBuiltin(backend) {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    fetch('{{ route("super-ai-core.providers.test-builtin") }}', {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'Content-Type': 'application/json'},
        body: JSON.stringify({backend: backend})
    }).then(r => r.json()).then(d => {
        alert((d.success ? '✓ ' : '✗ ') + (d.message ?? 'Unknown'));
    });
}
</script>
@endsection
