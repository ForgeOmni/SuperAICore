@extends('ai-core::layouts.app')
@section('title', __('ai-core::messages.processes'))
@section('content')
<h4><i class="bi bi-cpu me-1"></i>{{ __('ai-core::messages.processes') }}</h4>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead class="table-light">
                <tr><th>PID</th><th>User</th><th>CPU%</th><th>MEM%</th><th>Elapsed</th><th>Binary</th><th>Command</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($processes as $p)
                    <tr>
                        <td class="font-monospace small">{{ $p['pid'] }}</td>
                        <td>{{ $p['user'] }}</td>
                        <td>{{ $p['cpu'] }}</td>
                        <td>{{ $p['mem'] }}</td>
                        <td class="small">{{ $p['elapsed'] }}</td>
                        <td><span class="badge bg-secondary">{{ $p['binary'] }}</span></td>
                        <td class="small font-monospace text-truncate" style="max-width: 400px;">{{ $p['command'] }}</td>
                        <td>
                            <form method="POST" action="{{ route('ai-core.processes.kill') }}" class="d-inline" onsubmit="return confirm('Kill PID {{ $p['pid'] }}?')">
                                @csrf
                                <input type="hidden" name="pid" value="{{ $p['pid'] }}">
                                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-3">No AI CLI processes running.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
