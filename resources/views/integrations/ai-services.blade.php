@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.services'))
@section('content')
<h4><i class="bi bi-diagram-3 me-1"></i>{{ __('super-ai-core::messages.services') }}</h4>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead class="table-light">
                <tr><th>Capability</th><th>Services</th></tr>
            </thead>
            <tbody>
                @foreach($capabilities as $cap)
                    <tr>
                        <td class="fw-semibold">{{ $cap->name }} <code class="small">{{ $cap->slug }}</code></td>
                        <td>
                            @foreach($services->where('capability_id', $cap->id) as $svc)
                                <span class="badge bg-{{ $svc->is_active ? 'success' : 'secondary' }} me-1">
                                    {{ $svc->name }} ({{ $svc->protocol }})
                                </span>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
