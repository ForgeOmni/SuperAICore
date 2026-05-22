@extends(config('super-ai-core.layout', 'super-ai-core::layouts.app'))

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-0">
            <i class="bi bi-activity"></i> Dispatcher Traces
            <small class="text-muted ms-2">magic-trace-style black box recorder</small>
        </h1>
        <span class="text-muted small font-monospace">{{ $dir }}</span>
    </div>

    @if (empty($traces))
        <div class="alert alert-info">
            No trace files yet. Traces are written automatically on quota errors / provider rotation,
            and on demand via <code>php artisan dispatcher:dump-trace</code>.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>File</th>
                        <th>Producer</th>
                        <th>Trigger</th>
                        <th>Reason</th>
                        <th>Session</th>
                        <th class="text-end">Events</th>
                        <th class="text-end">Size</th>
                        <th>Modified</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($traces as $t)
                    @php
                        $badgeClass = match ($t['trigger']) {
                            'error'    => 'bg-danger',
                            'rotate'   => 'bg-warning text-dark',
                            'snapshot' => 'bg-success',
                            'timeout'  => 'bg-secondary',
                            'manual'   => 'bg-info text-dark',
                            default    => 'bg-secondary',
                        };
                    @endphp
                    <tr>
                        <td class="font-monospace small">{{ $t['filename'] }}</td>
                        <td><span class="badge bg-light text-dark">{{ $t['producer'] }}</span></td>
                        <td><span class="badge {{ $badgeClass }}">{{ $t['trigger'] }}</span></td>
                        <td class="text-muted small" style="max-width: 320px;">
                            {{ \Illuminate\Support\Str::limit($t['reason'] ?? '', 80) }}
                        </td>
                        <td class="font-monospace small text-muted">{{ $t['session_id'] ?? '—' }}</td>
                        <td class="text-end small">{{ $t['event_count'] ?? '?' }}</td>
                        <td class="text-end small">{{ number_format($t['size_bytes'] / 1024, 1) }} KB</td>
                        <td class="small text-muted">{{ $t['modified_at'] }}</td>
                        <td>
                            <a href="{{ route('super-ai-core.traces.show', ['filename' => $t['filename']]) }}"
                               class="btn btn-sm btn-outline-primary">View</a>
                            <a href="{{ route('super-ai-core.traces.raw', ['filename' => $t['filename']]) }}"
                               class="btn btn-sm btn-outline-secondary" download>Download</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-muted small mt-3">
            Tip: download a trace and open it at
            <a href="https://ui.perfetto.dev" target="_blank">ui.perfetto.dev</a>
            for advanced query and call-stack views.
            Wire format: <code>.claude/refs/ref-trace-format.md</code> (SuperTeam repo).
        </p>
    @endif
</div>
@endsection
