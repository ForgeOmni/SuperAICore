{{-- Compact MCP server card. Variables: $server (array), $mode ('installed'|'available') --}}
@php $key = $server['key']; @endphp

<div class="col-md-6 col-xl-4">
<div class="card mcp-card h-100" style="border-color:{{ $mode === 'installed' ? (($server['auth_status']['connected'] ?? false) ? '#059669' : ($server['dependency_ready'] ? 'var(--tf-primary)' : '#ef4444')) : 'var(--tf-border)' }}">
<div class="card-body p-3">
    {{-- Header row: icon + name + badge --}}
    <div class="d-flex align-items-center gap-2 mb-2">
        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 mcp-icon"
             style="background:{{ $server['color'] }};{{ $mode === 'available' ? 'opacity:.7' : '' }}">
            <i class="bi {{ $server['icon'] }} text-white"></i>
        </div>
        <div class="flex-grow-1 min-width-0">
            <div class="d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-bold text-truncate">{{ $server['name'] }}</h6>
                <span class="text-muted flex-shrink-0" style="font-size:.6875rem">{{ __('super-ai-core::integrations.type_' . $server['type']) }}</span>
            </div>
        </div>
        {{-- Status badge --}}
        @if($server['type'] === 'api-service')
            @if($server['installed'])
                <span class="badge flex-shrink-0 bg-success bg-opacity-10 text-success" style="font-size:.6875rem">
                    <i class="bi bi-key-fill me-1"></i>{{ __('super-ai-core::integrations.configured') }}
                </span>
            @else
                <span class="badge flex-shrink-0 bg-secondary bg-opacity-10 text-secondary" style="font-size:.6875rem">
                    <i class="bi bi-key me-1"></i>{{ __('super-ai-core::integrations.not_configured') }}
                </span>
            @endif
        @elseif($mode === 'installed')
            @if($server['requires_auth'] && $server['auth_status'])
                <span class="badge flex-shrink-0 {{ $server['auth_status']['connected'] ? 'bg-success' : 'bg-secondary' }} bg-opacity-10 {{ $server['auth_status']['connected'] ? 'text-success' : 'text-secondary' }}" style="font-size:.6875rem">
                    <i class="bi {{ $server['auth_status']['connected'] ? 'bi-check-circle-fill' : 'bi-x-circle' }} me-1"></i>{{ $server['auth_status']['connected'] ? __('super-ai-core::integrations.connected') : __('super-ai-core::integrations.not_connected') }}
                </span>
            @elseif($server['dependency_ready'])
                <span class="badge flex-shrink-0 bg-success bg-opacity-10 text-success" style="font-size:.6875rem">
                    <i class="bi bi-check-circle-fill me-1"></i>{{ __('super-ai-core::integrations.ready') }}
                </span>
            @else
                <span class="badge flex-shrink-0 bg-danger bg-opacity-10 text-danger" style="font-size:.6875rem">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ __('super-ai-core::integrations.dependency_missing') }}
                </span>
            @endif
        @else
            <span class="badge flex-shrink-0 bg-secondary bg-opacity-10 text-secondary" style="font-size:.6875rem">
                {{ __('super-ai-core::integrations.not_installed') }}
            </span>
        @endif
    </div>

    {{-- Description --}}
    @php
        $descKey = "super-ai-core::integrations.desc_{$key}";
        $descTrans = __($descKey);
    @endphp
    @if($descTrans !== $descKey)
    <p class="text-muted mb-2" style="font-size:.75rem;line-height:1.4">{{ $descTrans }}</p>
    @endif

    {{-- Session age (if connected) --}}
    @if($server['auth_status']['connected'] ?? false)
    <div class="mb-2" style="font-size:.75rem">
        <i class="bi bi-clock text-muted me-1"></i><span class="text-muted">{{ __('super-ai-core::integrations.session_age') }}: {{ $server['auth_status']['session_age_human'] ?? '' }}</span>
    </div>
    @endif

    {{-- Capabilities --}}
    @if(!empty($server['capabilities']))
    <div class="d-flex flex-wrap gap-1 mb-2" style="font-size:.6875rem">
        @foreach($server['capabilities'] as $cap)
            @php
                $capKey = "super-ai-core::integrations.cap_{$cap}";
                $capTrans = __($capKey);
            @endphp
            <span class="badge bg-light text-muted" style="font-weight:normal">{{ $capTrans !== $capKey ? $capTrans : $cap }}</span>
        @endforeach
    </div>
    @endif

    {{-- Config values display + edit for MCP servers with config_fields --}}
    @if($mode === 'installed' && $server['type'] !== 'api-service' && !empty($server['config_fields']))
        @php
            $cfgValues = [];
            try {
                $cfgValues = \SuperAICore\Models\IntegrationConfig::getAll($key);
            } catch (\Throwable $e) {}
            if (empty($cfgValues)) {
                try {
                    $mcpCfg = \SuperAICore\Services\McpManager::readConfig();
                    $mcpEnv = $mcpCfg['mcpServers'][$key]['env'] ?? [];
                    foreach ($server['config_fields'] as $fk => $fd) {
                        $envKey = $fd['env_key'] ?? strtoupper($fk);
                        if (!empty($mcpEnv[$envKey])) $cfgValues[$fk] = $mcpEnv[$envKey];
                    }
                } catch (\Throwable $e) {}
            }
            $hasCfg = !empty(array_filter($cfgValues));
        @endphp
        <div class="mb-2 mcp-config-display" style="font-size:.7rem">
            @if($hasCfg)
                @foreach($server['config_fields'] as $fk => $fd)
                    @if(!empty($cfgValues[$fk]))
                    <span class="badge bg-light text-dark border me-1">
                        <i class="bi bi-gear me-1"></i><code>{{ ($fd['is_secret'] ?? false) ? substr($cfgValues[$fk], 0, 4) . '····' : $cfgValues[$fk] }}</code>
                    </span>
                    @endif
                @endforeach
                <button type="button" class="btn btn-link btn-sm p-0 ms-1" style="font-size:.7rem"
                        onclick="this.closest('.mcp-config-display').querySelector('.config-edit-form').classList.toggle('d-none')">
                    <i class="bi bi-pencil"></i> {{ __('super-ai-core::integrations.change_key') }}
                </button>
            @else
                <span class="text-warning" style="font-size:.7rem"><i class="bi bi-exclamation-triangle me-1"></i>{{ __('super-ai-core::integrations.not_configured') }}</span>
            @endif
            <div class="config-edit-form {{ $hasCfg ? 'd-none' : '' }} mt-1">
                <form action="{{ route('super-ai-core.integrations.install', $key) }}" method="POST" class="d-flex gap-1 align-items-center flex-wrap">
                    @csrf
                    @foreach($server['config_fields'] as $fk => $fd)
                    <input type="text" name="config[{{ $fk }}]" class="form-control form-control-sm" style="font-size:.75rem;min-width:200px;flex:1"
                           placeholder="{{ $fd['placeholder'] ?? '' }}" value="{{ $cfgValues[$fk] ?? '' }}">
                    @endforeach
                    <button type="submit" class="btn btn-primary btn-sm py-0 px-2" style="font-size:.75rem">
                        <i class="bi bi-check-lg"></i>
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- Actions --}}
    <div class="d-flex flex-wrap gap-1 api-service-actions">
        @if($mode === 'installed')
            {{-- Auth actions --}}
            @if($server['requires_auth'])
                @if(!($server['auth_status']['connected'] ?? false))
                    @if($server['dependency_ready'])
                    <form action="{{ route('super-ai-core.integrations.auth', $key) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm py-0 px-2" style="font-size:.75rem">
                            <i class="bi bi-box-arrow-in-right me-1"></i>{{ __('super-ai-core::integrations.connect') }}
                        </button>
                    </form>
                    @endif
                @else
                    <form action="{{ route('super-ai-core.integrations.auth', $key) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:.75rem">
                            <i class="bi bi-arrow-repeat me-1"></i>{{ __('super-ai-core::integrations.reconnect') }}
                        </button>
                    </form>
                    <form action="{{ route('super-ai-core.integrations.clearAuth', $key) }}" method="POST" class="d-inline"
                          onsubmit="return confirm('{{ __('super-ai-core::integrations.disconnect_confirm', ['name' => $server['name']]) }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size:.75rem">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                @endif
            @endif

            @if($server['type'] !== 'api-service')
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 btn-test" style="font-size:.75rem"
                    data-url="{{ route('super-ai-core.integrations.test', $key) }}">
                <i class="bi bi-activity"></i>
            </button>
            <form action="{{ route('super-ai-core.integrations.uninstall', $key) }}" method="POST" class="d-inline"
                  onsubmit="return confirm('{{ __('super-ai-core::integrations.uninstall_confirm', ['name' => $server['name']]) }}')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size:.75rem">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
            @else
            {{-- API Service: show configured values + modal edit --}}
            @php
                $configValues = [];
                try { $configValues = \SuperAICore\Models\IntegrationConfig::getAll($key); } catch (\Throwable $e) {}
                $hasConfig = !empty(array_filter($configValues));
            @endphp
            @if($hasConfig)
                @foreach($server['config_fields'] ?? [] as $fk => $fd)
                    @if(!empty($configValues[$fk]))
                    @php
                        $labelKey = "super-ai-core::integrations.{$fd['label']}";
                        $labelTrans = __($labelKey);
                        if ($labelTrans === $labelKey) $labelTrans = $fd['label'];
                    @endphp
                    <span class="badge bg-light text-dark border me-1" style="font-size:.65rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ ($fd['is_secret'] ?? false) ? '••••••' : $configValues[$fk] }}">
                        <i class="bi bi-check-circle text-success me-1"></i>{{ \Illuminate\Support\Str::limit($labelTrans, 30) }}
                    </span>
                    @endif
                @endforeach
            @endif
            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.75rem"
                    data-bs-toggle="modal" data-bs-target="#configModal-{{ $key }}">
                <i class="bi bi-gear me-1"></i>{{ $hasConfig ? __('super-ai-core::integrations.change_key') : __('super-ai-core::integrations.save_key') }}
            </button>

            @include('super-ai-core::integrations._config-modal', ['server' => $server, 'configValues' => $configValues])
            @endif
        @else
            {{-- Dependency warning --}}
            @if(!$server['dependency_ready'] && $server['type'] !== 'python')
            <span class="text-warning me-1" style="font-size:.6875rem">
                <i class="bi bi-exclamation-triangle"></i>
                @if($server['type'] === 'uvx') uvx
                @elseif($server['type'] === 'npx') npx
                @endif
            </span>
            @endif

            @if($server['type'] === 'api-service')
            <button type="button" class="btn btn-primary btn-sm py-0 px-2" style="font-size:.75rem"
                    data-bs-toggle="modal" data-bs-target="#configModal-{{ $key }}">
                <i class="bi bi-gear me-1"></i>{{ __('super-ai-core::integrations.configure') }}
            </button>
            @include('super-ai-core::integrations._config-modal', ['server' => $server, 'configValues' => []])
            @else
            {{-- MCP Server: inline config fields + Install --}}
            <form action="{{ route('super-ai-core.integrations.install', $key) }}" method="POST" class="{{ !empty($server['config_fields']) ? 'w-100' : 'd-inline' }}">
                @csrf
                @if(!empty($server['config_fields']))
                    @foreach($server['config_fields'] as $fieldKey => $fieldDef)
                    @php
                        $labelKey = "super-ai-core::integrations.{$fieldDef['label']}";
                        $labelTrans = __($labelKey);
                        if ($labelTrans === $labelKey) $labelTrans = $fieldDef['label'];
                    @endphp
                    <div class="mb-2">
                        <input type="text" name="config[{{ $fieldKey }}]" class="form-control form-control-sm"
                               placeholder="{{ $fieldDef['placeholder'] ?? '' }}"
                               style="font-size:.75rem"
                               {{ ($fieldDef['required'] ?? false) ? 'required' : '' }}>
                        <div class="form-text" style="font-size:.625rem">{{ $labelTrans }}</div>
                    </div>
                    @endforeach
                @endif
                <button type="submit" class="btn btn-primary btn-sm py-0 px-2" style="font-size:.75rem"
                        @if(!$server['dependency_ready'] && $server['type'] !== 'python') disabled @endif>
                    <i class="bi bi-download me-1"></i>{{ __('super-ai-core::integrations.install') }}
                </button>
            </form>
            @endif
        @endif
    </div>

    {{-- Used-by info --}}
    @php
        $usedKey = "super-ai-core::integrations.used_by_{$key}";
        $usedTrans = __($usedKey);
    @endphp
    @if($mode === 'installed' && $usedTrans !== $usedKey)
    <div class="mt-2 p-1 rounded" style="background:var(--tf-bg);font-size:.6875rem">
        <i class="bi bi-info-circle text-muted me-1"></i><span class="text-muted">{{ $usedTrans }}</span>
    </div>
    @endif
</div>
</div>
</div>
