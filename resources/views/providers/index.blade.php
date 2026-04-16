@extends('ai-core::layouts.app')

@section('title', __('ai-core::messages.providers'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4><i class="bi bi-cpu me-1"></i>{{ __('ai-core::messages.providers') }}</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#new-provider-modal">
        <i class="bi bi-plus-lg"></i> {{ __('ai-core::messages.create_provider') }}
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{ __('ai-core::messages.name') }}</th>
                    <th>{{ __('ai-core::messages.backend') }}</th>
                    <th>{{ __('ai-core::messages.type') }}</th>
                    <th>{{ __('ai-core::messages.is_active') }}</th>
                    <th>{{ __('ai-core::messages.api_key') }}</th>
                    <th>{{ __('ai-core::messages.actions') }}</th>
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
                                {{ $p->is_active ? __('ai-core::messages.activate') : '—' }}
                            </span>
                        </td>
                        <td class="small font-monospace">{{ $p->masked_api_key ?? '—' }}</td>
                        <td>
                            @if($p->is_active)
                                <form method="POST" action="{{ route('ai-core.providers.deactivate', $p) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-secondary btn-sm">{{ __('ai-core::messages.deactivate') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('ai-core.providers.activate', $p) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-success btn-sm">{{ __('ai-core::messages.activate') }}</button>
                                </form>
                            @endif
                            <button class="btn btn-outline-primary btn-sm" onclick="testProvider({{ $p->id }})">{{ __('ai-core::messages.test_connection') }}</button>
                            <form method="POST" action="{{ route('ai-core.providers.destroy', $p) }}" class="d-inline" onsubmit="return confirm('{{ __('ai-core::messages.delete') }}?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm">{{ __('ai-core::messages.delete') }}</button>
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
        <form class="modal-content" method="POST" action="{{ route('ai-core.providers.store') }}">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">{{ __('ai-core::messages.create_provider') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label small">{{ __('ai-core::messages.name') }}</label>
                    <input type="text" name="name" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('ai-core::messages.backend') }}</label>
                    <select name="backend" class="form-select form-select-sm" required>
                        <option value="claude">claude</option>
                        <option value="codex">codex</option>
                        <option value="superagent">superagent</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('ai-core::messages.type') }}</label>
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
                    <label class="form-label small">{{ __('ai-core::messages.api_key') }}</label>
                    <input type="password" name="api_key" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label small">{{ __('ai-core::messages.base_url') }}</label>
                    <input type="url" name="base_url" class="form-control form-control-sm" placeholder="https://api.anthropic.com">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">{{ __('ai-core::messages.cancel') }}</button>
                <button type="submit" class="btn btn-sm btn-primary">{{ __('ai-core::messages.submit') }}</button>
            </div>
        </form>
    </div>
</div>

<script>
function testProvider(id) {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    fetch('{{ url("ai-core/providers") }}/' + id + '/test', {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}
    }).then(r => r.json()).then(d => {
        alert((d.success ? '✓ ' : '✗ ') + (d.message ?? 'Unknown'));
    });
}
</script>
@endsection
