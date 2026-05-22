@extends(config('super-ai-core.layout', 'super-ai-core::layouts.app'))

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h1 class="h5 mb-0 font-monospace">{{ $filename }}</h1>
        <div>
            <a href="{{ route('super-ai-core.traces.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <a href="{{ $raw_url }}" class="btn btn-sm btn-outline-primary" download>
                <i class="bi bi-download"></i> Raw JSON
            </a>
            <a href="https://ui.perfetto.dev" target="_blank" class="btn btn-sm btn-outline-info">
                Open in Perfetto
            </a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card"><div class="card-body py-2">
                <div class="text-muted small">Producer</div>
                <div class="fw-bold">{{ $metadata['producer'] ?? '?' }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body py-2">
                <div class="text-muted small">Trigger</div>
                <div class="fw-bold">{{ $metadata['trigger'] ?? '?' }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body py-2">
                <div class="text-muted small">Events</div>
                <div class="fw-bold">{{ $event_count }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body py-2">
                <div class="text-muted small">Size</div>
                <div class="fw-bold">{{ number_format($size_bytes / 1024, 1) }} KB</div>
            </div></div>
        </div>
    </div>

    @if (!empty($metadata['trigger_reason']))
        <div class="alert alert-light border">
            <strong>Reason:</strong> {{ $metadata['trigger_reason'] }}
        </div>
    @endif

    {{-- Bundled lightweight viewer iframe — fetches the raw JSON over HTTP and
         renders a Perfetto-compatible timeline with category filters. --}}
    <iframe
        id="trace-iframe"
        src="{{ url('/super-ai-core/traces/viewer.html?file=' . urlencode($raw_url)) }}"
        style="width: 100%; height: 70vh; border: 1px solid #dee2e6; border-radius: 6px;"
        title="Trace timeline">
    </iframe>

    <details class="mt-3">
        <summary class="text-muted small">Trace metadata (raw)</summary>
        <pre class="bg-light p-3 small">{{ json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </details>
</div>
@endsection
