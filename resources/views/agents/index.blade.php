@extends('super-ai-core::layouts.app')
@section('title', 'Agents')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-people me-1"></i>SuperTeam Agents</h4>
    <span class="badge bg-secondary">{{ $total }} agents</span>
</div>

@if (count($grouped) === 0)
    <div class="alert alert-warning">
        No agents found. Configure <code>super-ai-core.agent_catalog.paths</code>
        or place agents under <code>.claude/agents/*.md</code> at the project root.
    </div>
@endif

@foreach($grouped as $category => $agents)
<div class="mb-4">
    <h5 class="text-muted text-uppercase small mb-2">
        {{ $category }}
        <span class="badge bg-light text-dark ms-1">{{ count($agents) }}</span>
    </h5>
    <div class="row g-3">
        @foreach($agents as $agent)
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <a href="{{ route('super-ai-core.agents.show', ['name' => $agent['name']]) }}"
                           class="text-decoration-none">
                            <code class="text-primary">{{ $agent['name'] }}</code>
                        </a>
                        @if(!empty($agent['model']))
                            <span class="badge bg-light text-dark small">{{ $agent['model'] }}</span>
                        @endif
                    </div>
                    <p class="small text-muted mb-0" style="display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;">
                        {{ $agent['description'] }}
                    </p>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endforeach
@endsection
