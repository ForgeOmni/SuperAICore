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
<div class="row g-3 mb-3">
    @foreach(['claude' => 'Claude Code CLI', 'codex' => 'Codex CLI', 'superagent' => 'SuperAgent SDK'] as $be => $label)
        @php $st = $cliStatuses[$be] ?? []; @endphp
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 {{ ($defaultBackend === $be) ? 'border-primary border-2' : '' }}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h6 class="mb-0">{{ $label }}</h6>
                            <code class="small text-muted">{{ $be }}</code>
                        </div>
                        @if(!empty($st['installed']))
                            <span class="badge bg-success">{{ __('super-ai-core::messages.cli_installed') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ __('super-ai-core::messages.cli_not_installed') }}</span>
                        @endif
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
                            @endif
                        </div>
                    @endif

                    <form method="POST" action="{{ route('super-ai-core.providers.default-backend') }}" class="mt-3">
                        @csrf
                        <input type="hidden" name="backend" value="{{ $be }}">
                        @if($defaultBackend === $be)
                            <button type="button" class="btn btn-primary btn-sm w-100" disabled>
                                <i class="bi bi-check2"></i> {{ __('super-ai-core::messages.default_backend') }}
                            </button>
                        @else
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                {{ __('super-ai-core::messages.set_default') }}
                            </button>
                        @endif
                    </form>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ __('super-ai-core::messages.name') }}</th>
                    <th>{{ __('super-ai-core::messages.backend') }}</th>
                    <th>{{ __('super-ai-core::messages.type') }}</th>
                    <th>{{ __('super-ai-core::messages.is_active') }}</th>
                    <th>{{ __('super-ai-core::messages.api_key') }}</th>
                    <th>{{ __('super-ai-core::messages.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($providers as $p)
                    <tr>
                        <td class="fw-semibold">{{ $p->name }}</td>
                        <td><span class="badge bg-secondary">{{ $p->backend }}</span></td>
                        <td class="small text-muted">{{ $p->type }}</td>
                        <td>
                            <span class="badge bg-{{ $p->is_active ? 'success' : 'secondary' }}">
                                {{ $p->is_active ? __('super-ai-core::messages.activate') : '—' }}
                            </span>
                        </td>
                        <td class="small font-monospace">{{ $p->masked_api_key ?? '—' }}</td>
                        <td>
                            @if($p->is_active)
                                <form method="POST" action="{{ route('super-ai-core.providers.deactivate', $p) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-secondary btn-sm">{{ __('super-ai-core::messages.deactivate') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('super-ai-core.providers.activate', $p) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-success btn-sm">{{ __('super-ai-core::messages.activate') }}</button>
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
                    <tr><td colspan="6" class="text-center text-muted py-4">No providers configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

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
                    <select name="backend" class="form-select form-select-sm" required>
                        <option value="claude">claude</option>
                        <option value="codex">codex</option>
                        <option value="superagent">superagent</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('super-ai-core::messages.type') }}</label>
                    <select name="type" class="form-select form-select-sm" required>
                        <option value="builtin">builtin</option>
                        <option value="anthropic">anthropic</option>
                        <option value="anthropic-proxy">anthropic-proxy</option>
                        <option value="bedrock">bedrock</option>
                        <option value="vertex">vertex</option>
                        <option value="openai">openai</option>
                        <option value="openai-compatible">openai-compatible</option>
                    </select>
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
function testProvider(id) {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    fetch('{{ url("super-ai-core/providers") }}/' + id + '/test', {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}
    }).then(r => r.json()).then(d => {
        alert((d.success ? '✓ ' : '✗ ') + (d.message ?? 'Unknown'));
    });
}
</script>
@endsection
