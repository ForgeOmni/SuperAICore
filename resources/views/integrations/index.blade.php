@extends('ai-core::layouts.app')
@section('title', __('ai-core::messages.integrations'))
@section('content')
<h4><i class="bi bi-plug me-1"></i>{{ __('ai-core::messages.integrations') }}</h4>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <p class="text-muted">MCP server registry:</p>
        <ul>
            @forelse($registry as $key => $def)
                <li><code>{{ $key }}</code> — {{ $def['name'] ?? '' }}</li>
            @empty
                <li class="text-muted">No MCP servers registered (McpManager port pending).</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
