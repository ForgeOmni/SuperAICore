@extends('super-ai-core::layouts.app')
@section('title', $agent['name'])
@section('content')
<div class="mb-3">
    <a href="{{ route('super-ai-core.agents.index') }}" class="text-decoration-none small">
        ← Back to agents
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h3 class="mb-0"><code>{{ $agent['name'] }}</code></h3>
            @if(!empty($agent['model']))
                <span class="badge bg-light text-dark">{{ $agent['model'] }}</span>
            @endif
        </div>
        <p class="lead text-muted">{{ $agent['description'] }}</p>
        <hr>
        <pre style="white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, 'Roboto Mono', monospace; font-size: 0.85rem;">{{ $body }}</pre>
    </div>
    <div class="card-footer bg-light small text-muted">
        <code>{{ $agent['file'] }}</code>
    </div>
</div>
@endsection
