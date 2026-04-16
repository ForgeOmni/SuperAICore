@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::messages.task_models'))
@section('content')
<h4 class="mb-3"><i class="bi bi-sliders me-1"></i>{{ __('super-ai-core::messages.task_models') }}</h4>

{{-- Backend / provider picker --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-center">
            <div class="col-auto"><strong>{{ __('super-ai-core::messages.backend') }}:</strong></div>
            @foreach(['claude', 'codex', 'superagent'] as $be)
                <div class="col-auto">
                    <a href="{{ route('super-ai-core.task-models.index', ['backend' => $be]) }}"
                       class="btn btn-sm {{ $selectedBackend === $be && !$selectedProviderId ? 'btn-primary' : 'btn-outline-primary' }}">
                        {{ ucfirst($be) }} ({{ __('super-ai-core::messages.builtin') }})
                    </a>
                </div>
            @endforeach
        </div>

        @foreach($providersByBackend as $be => $beProviders)
            @if($beProviders->isEmpty()) @continue @endif
            <div class="row g-2 align-items-center mt-2">
                <div class="col-auto text-muted small">{{ ucfirst($be) }} providers:</div>
                @foreach($beProviders as $p)
                    <div class="col-auto">
                        <a href="{{ route('super-ai-core.task-models.index', ['provider_id' => $p->id]) }}"
                           class="btn btn-sm {{ (int) $selectedProviderId === $p->id ? 'btn-primary' : 'btn-outline-secondary' }}">
                            {{ $p->name }}
                        </a>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>

{{-- Settings form --}}
<form method="POST" action="{{ route('super-ai-core.task-models.save') }}">
    @csrf
    <input type="hidden" name="backend" value="{{ $selectedBackend }}">
    @if($selectedProviderId)
        <input type="hidden" name="provider_id" value="{{ $selectedProviderId }}">
    @endif

    {{-- Default row --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <strong>{{ __('super-ai-core::messages.default_task_model') }}</strong>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small">{{ __('super-ai-core::messages.model') }}</label>
                    <input type="text" name="default" class="form-control form-control-sm" value="{{ $settings['default']['model'] ?? '' }}" placeholder="claude-sonnet-4-6">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">{{ __('super-ai-core::messages.effort') }}</label>
                    <select name="default_effort" class="form-select form-select-sm">
                        <option value="">—</option>
                        @foreach(['low', 'medium', 'high', 'max'] as $e)
                            <option value="{{ $e }}" @selected(($settings['default']['effort'] ?? '') === $e)>{{ $e }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Per task type --}}
    @foreach($taskGroups as $groupKey => $group)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>{{ $group['label'] ?? $groupKey }}</strong>
            </div>
            <div class="card-body">
                @foreach($group['types'] ?? [] as $typeKey => $typeDef)
                    @php $s = $settings[$typeKey] ?? []; @endphp
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-3">
                            <label class="form-label small">{{ $typeDef['label'] ?? $typeKey }}</label>
                            <code class="small text-muted d-block">{{ $typeKey }}</code>
                        </div>
                        <div class="col-md-5">
                            <input type="text" name="{{ $typeKey }}_model" class="form-control form-control-sm" value="{{ $s['model'] ?? '' }}" placeholder="{{ __('super-ai-core::messages.inherit_default') }}">
                        </div>
                        <div class="col-md-4">
                            <select name="{{ $typeKey }}_effort" class="form-select form-select-sm">
                                <option value="">{{ __('super-ai-core::messages.inherit_default') }}</option>
                                @foreach(['low', 'medium', 'high', 'max'] as $e)
                                    <option value="{{ $e }}" @selected(($s['effort'] ?? '') === $e)>{{ $e }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="text-end">
        <button type="submit" class="btn btn-primary">{{ __('super-ai-core::messages.save_settings') }}</button>
    </div>
</form>
@endsection
