@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::integrations.ai_route_title'))

@section('content')
<div class="container-fluid py-4" style="max-width:1000px">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-signpost-split me-2"></i>{{ __('super-ai-core::integrations.ai_route_title') }}</h4>
            <p class="text-muted mb-0" style="font-size:.875rem">{{ __('super-ai-core::integrations.ai_route_subtitle') }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('super-ai-core.services.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>{{ __('super-ai-core::integrations.ai_services_title') }}
            </a>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addRouteModal">
                <i class="bi bi-plus-lg me-1"></i>{{ __('super-ai-core::integrations.ai_route_add') }}
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2" style="font-size:.875rem">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" style="font-size:.7rem"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm mb-0" style="font-size:.8125rem">
                <thead>
                    <tr class="text-muted">
                        <th>{{ __('super-ai-core::integrations.ai_route_task_type') }}</th>
                        <th>{{ __('super-ai-core::integrations.ai_route_capability') }}</th>
                        <th>{{ __('super-ai-core::integrations.ai_route_service') }}</th>
                        <th style="width:80px">{{ __('super-ai-core::integrations.ai_route_priority') }}</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($routings as $r)
                    <tr class="{{ !$r->is_active ? 'text-muted' : '' }}">
                        <td>
                            @if($r->task_type === '*')
                                <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:.75rem">{{ __('super-ai-core::integrations.ai_route_wildcard') }}</span>
                            @else
                                <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.75rem">{{ $taskTypes[$r->task_type] ?? $r->task_type }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-warning bg-opacity-10 text-warning" style="font-size:.7rem">
                                <i class="bi bi-lightning-fill me-1"></i>{{ $r->capability->name ?? '—' }}
                            </span>
                        </td>
                        <td>
                            <span class="fw-semibold">{{ $r->service->name ?? '—' }}</span>
                            @if($r->service)
                                <br><code style="font-size:.65rem;color:var(--tf-text-muted)">{{ $r->service->model }} @ {{ $r->service->base_url }}</code>
                            @endif
                        </td>
                        <td class="text-center">{{ $r->priority }}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" style="font-size:.65rem" onclick="editRouting({{ json_encode($r) }})"><i class="bi bi-pencil"></i></button>
                            <form action="{{ route('super-ai-core.routings.toggle', $r) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm {{ $r->is_active ? 'btn-outline-success' : 'btn-outline-secondary' }}" style="font-size:.65rem">
                                    <i class="bi {{ $r->is_active ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                                </button>
                            </form>
                            <form action="{{ route('super-ai-core.routings.destroy', $r) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:.65rem"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">
                        <i class="bi bi-signpost-split" style="font-size:1.5rem"></i>
                        <p class="mt-2 mb-0">{{ __('super-ai-core::integrations.ai_route_empty') }}</p>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ═══ Add Route Modal ═══ --}}
<div class="modal fade" id="addRouteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:var(--tf-radius)">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-signpost-split me-1"></i>{{ __('super-ai-core::integrations.ai_route_add') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.75rem"></button></div>
            <form action="{{ route('super-ai-core.routings.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">{{ __('super-ai-core::integrations.ai_route_task_type') }}</label>
                        <select class="form-select form-select-sm" name="task_type" required>
                            <option value="*">{{ __('super-ai-core::integrations.ai_route_wildcard') }}</option>
                            @foreach($taskTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('super-ai-core::integrations.ai_route_capability') }}</label>
                        <select class="form-select form-select-sm" name="capability_id" required>
                            @foreach($capabilities as $cap)<option value="{{ $cap->id }}">{{ $cap->name }} ({{ $cap->slug }})</option>@endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('super-ai-core::integrations.ai_route_service') }}</label>
                        <select class="form-select form-select-sm" name="service_id" required>
                            @foreach($services as $svc)<option value="{{ $svc->id }}">{{ $svc->name }} — {{ $svc->model }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">{{ __('super-ai-core::integrations.ai_route_priority') }} <small class="text-muted fw-normal">0 = default</small></label>
                        <input type="number" class="form-control form-control-sm" name="priority" value="0">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('tasks.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('super-ai-core::integrations.ai_route_add') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ═══ Edit Route Modal ═══ --}}
<div class="modal fade" id="editRouteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:var(--tf-radius)">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-pencil me-1"></i>{{ __('super-ai-core::integrations.ai_route_edit') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.75rem"></button></div>
            <form id="editRouteForm" method="POST">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">{{ __('super-ai-core::integrations.ai_route_task_type') }}</label>
                        <select class="form-select form-select-sm" name="task_type" id="editRouteTaskType" required>
                            <option value="*">{{ __('super-ai-core::integrations.ai_route_wildcard') }}</option>
                            @foreach($taskTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('super-ai-core::integrations.ai_route_service') }}</label>
                        <select class="form-select form-select-sm" name="service_id" id="editRouteService" required>
                            @foreach($services as $svc)<option value="{{ $svc->id }}">{{ $svc->name }} — {{ $svc->model }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">{{ __('super-ai-core::integrations.ai_route_priority') }}</label>
                        <input type="number" class="form-control form-control-sm" name="priority" id="editRoutePriority" value="0">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('tasks.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('super-ai-core::integrations.ai_route_edit') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function editRouting(r) {
    document.getElementById('editRouteForm').action = '/integrations/ai-service-routing/' + r.id;
    $('#editRouteTaskType').val(r.task_type).trigger('change');
    $('#editRouteService').val(r.service_id).trigger('change');
    document.getElementById('editRoutePriority').value = r.priority;
    new bootstrap.Modal(document.getElementById('editRouteModal')).show();
}
</script>
@endpush
