@extends('ai-core::layouts.app')
@section('title', __('ai-core::messages.routing'))
@section('content')
<h4><i class="bi bi-signpost me-1"></i>{{ __('ai-core::messages.routing') }}</h4>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead class="table-light">
                <tr><th>Task Type</th><th>Capability</th><th>Service</th><th>Priority</th><th>Active</th></tr>
            </thead>
            <tbody>
                @forelse($routings as $r)
                    <tr>
                        <td><code>{{ $r->task_type }}</code></td>
                        <td>{{ $r->capability->name ?? '—' }}</td>
                        <td>{{ $r->service->name ?? '—' }}</td>
                        <td>{{ $r->priority }}</td>
                        <td><span class="badge bg-{{ $r->is_active ? 'success' : 'secondary' }}">{{ $r->is_active ? '✓' : '✗' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">No routing rules configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
