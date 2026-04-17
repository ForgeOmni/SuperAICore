{{-- Shared config modal for API-service type MCP servers. Vars: $server, $configValues --}}
@php $key = $server['key']; @endphp
<div class="modal fade" id="configModal-{{ $key }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('super-ai-core.integrations.install', $key) }}" method="POST">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title">
                        <i class="bi {{ $server['icon'] }} me-2" style="color:{{ $server['color'] }}"></i>{{ $server['name'] }} — {{ __('super-ai-core::integrations.configure') }}
                    </h6>
                    <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @foreach($server['config_fields'] ?? [] as $fieldKey => $fieldDef)
                    @php
                        $labelKey = "super-ai-core::integrations.{$fieldDef['label']}";
                        $labelTrans = __($labelKey);
                        if ($labelTrans === $labelKey) $labelTrans = $fieldDef['label'];
                        $isSecret = $fieldDef['is_secret'] ?? false;
                        $existing = $configValues[$fieldKey] ?? '';
                    @endphp
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.8rem">{{ $labelTrans }}</label>
                        <input type="{{ $isSecret ? 'password' : 'text' }}"
                               name="config[{{ $fieldKey }}]"
                               class="form-control form-control-sm"
                               placeholder="{{ $fieldDef['placeholder'] ?? '' }}"
                               value="{{ $isSecret ? '' : $existing }}">
                        @if($isSecret && !empty($existing))
                        <div class="form-text" style="font-size:.7rem">
                            <i class="bi bi-check-circle text-success me-1"></i>{{ __('super-ai-core::integrations.key_already_set') }}
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">{{ __('super-ai-core::integrations.cancel') }}</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-lg me-1"></i>{{ __('super-ai-core::integrations.save_config') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
