@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::integrations.title'))
@section('content')
<div class="tf-page-header d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4"><i class="bi bi-plug me-2"></i>{{ __('super-ai-core::integrations.title') }}</h1>
        <p class="text-muted mb-0" style="font-size:.875rem">{{ __('super-ai-core::integrations.subtitle') }}</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap" style="font-size:.875rem">
        @php
            $installedCount = collect($servers)->where('installed', true)->count();
            $totalCount = count($servers);
        @endphp
        <span class="badge bg-success bg-opacity-10 text-success">{{ $installedCount }} {{ __('super-ai-core::integrations.installed') }}</span>
        <span class="badge bg-secondary bg-opacity-10 text-secondary">{{ $totalCount - $installedCount }} {{ __('super-ai-core::integrations.not_installed') }}</span>
        <div class="btn-group ms-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btn-batch-check">
                <i class="bi bi-clipboard-check me-1"></i>{{ __('super-ai-core::integrations.batch_check') }}
            </button>
            @if($totalCount - $installedCount > 0)
            <button type="button" class="btn btn-outline-success btn-sm" id="btn-batch-install">
                <i class="bi bi-download me-1"></i>{{ __('super-ai-core::integrations.batch_install') }}
            </button>
            @endif
        </div>
    </div>
</div>

{{-- Tabs --}}
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-installed" type="button">
            <i class="bi bi-check-circle me-1"></i>{{ __('super-ai-core::integrations.section_installed') }}
            <span class="badge bg-success bg-opacity-10 text-success ms-1">{{ $installedCount }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-available" type="button">
            <i class="bi bi-plus-circle me-1"></i>{{ __('super-ai-core::integrations.section_available') }}
            <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1">{{ $totalCount - $installedCount }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-system-tools" type="button">
            <i class="bi bi-tools me-1"></i>{{ __('super-ai-core::integrations.section_system_tools') }}
            <span class="badge bg-info bg-opacity-10 text-info ms-1">{{ count($systemTools ?? []) }}</span>
        </button>
    </li>
</ul>

@php
    $installedServers = collect($servers)->where('installed', true)->groupBy('category', true);
    $availableServers = collect($servers)->filter(fn($s) => !$s['installed'] && $s['in_registry'])->groupBy('category', true);
    $categoryOrder = collect($categories)->sortBy('order')->keys()->toArray();
    $categoryOrder[] = 'other';
    $catLabel = function ($cat) {
        $key = "super-ai-core::integrations.cat_{$cat}";
        $trans = __($key);
        return $trans === $key ? ucfirst($cat) : $trans;
    };
@endphp

<div class="tab-content">
    {{-- ═══ Installed Tab ═══ --}}
    <div class="tab-pane fade show active" id="tab-installed">
        @if($installedCount === 0)
            <div class="text-center py-5 text-muted">
                <i class="bi bi-plug" style="font-size:2.5rem;opacity:.3"></i>
                <p class="mt-2 mb-0">{{ __('super-ai-core::integrations.section_available') }}</p>
            </div>
        @else
            @foreach($categoryOrder as $cat)
                @if(isset($installedServers[$cat]))
                <div class="mb-4">
                    <h6 class="text-muted fw-bold mb-3" style="font-size:.8125rem;text-transform:uppercase;letter-spacing:.05em">
                        <i class="bi {{ $categories[$cat]['icon'] ?? 'bi-puzzle' }} me-1"></i>{{ $catLabel($cat) }}
                    </h6>
                    <div class="row g-3">
                        @foreach($installedServers[$cat] as $server)
                            @include('super-ai-core::integrations._server-card', ['server' => $server, 'mode' => 'installed'])
                        @endforeach
                    </div>
                </div>
                @endif
            @endforeach
        @endif
    </div>

    {{-- ═══ Available Tab ═══ --}}
    <div class="tab-pane fade" id="tab-available">
        @if($availableServers->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-check-all" style="font-size:2.5rem;opacity:.3"></i>
                <p class="mt-2 mb-0">All servers installed.</p>
            </div>
        @else
            @foreach($categoryOrder as $cat)
                @if(isset($availableServers[$cat]))
                <div class="mb-4">
                    <h6 class="text-muted fw-bold mb-3" style="font-size:.8125rem;text-transform:uppercase;letter-spacing:.05em">
                        <i class="bi {{ $categories[$cat]['icon'] ?? 'bi-puzzle' }} me-1"></i>{{ $catLabel($cat) }}
                    </h6>
                    <div class="row g-3">
                        @foreach($availableServers[$cat] as $server)
                            @include('super-ai-core::integrations._server-card', ['server' => $server, 'mode' => 'available'])
                        @endforeach
                    </div>
                </div>
                @endif
            @endforeach
        @endif
    </div>

    {{-- ═══ System Tools Tab ═══ --}}
    <div class="tab-pane fade" id="tab-system-tools">
        <div class="row g-4">
            @foreach($systemTools ?? [] as $tool)
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="me-3">
                                <i class="bi {{ $tool['icon'] }}" style="font-size:2rem;color:{{ $tool['color'] }}"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-0">{{ $tool['name'] }}</h5>
                                @if($tool['installed'])
                                    <span class="badge bg-success bg-opacity-10 text-success">
                                        <i class="bi bi-check-circle-fill me-1"></i>{{ __('super-ai-core::integrations.installed') }}
                                        @if($tool['version']) v{{ $tool['version'] }} @endif
                                    </span>
                                @else
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                        <i class="bi bi-x-circle me-1"></i>{{ __('super-ai-core::integrations.not_installed') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <p class="text-muted small mb-3">{{ $tool['description'] }}</p>

                        @if($tool['key'] === 'tesseract' && $tool['installed'])
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">{{ __('super-ai-core::integrations.tesseract_languages') }}:</small>
                                <div>
                                    @foreach($tool['languages'] ?? [] as $code => $name)
                                        <span class="badge bg-primary bg-opacity-10 text-primary me-1 mb-1">{{ $name }}</span>
                                    @endforeach
                                    @if(empty($tool['languages']))
                                        <span class="text-muted small">{{ __('super-ai-core::integrations.no_languages_installed') }}</span>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="d-flex gap-2">
                            @if(!$tool['installed'])
                                <button class="btn btn-sm btn-primary" onclick="showInstallCommands('{{ $tool['key'] }}')">
                                    <i class="bi bi-download me-1"></i>{{ __('super-ai-core::integrations.install') }}
                                </button>
                            @else
                                <button class="btn btn-sm btn-success" disabled>
                                    <i class="bi bi-check-circle me-1"></i>{{ __('super-ai-core::integrations.installed') }}
                                </button>
                                @if($tool['key'] === 'tesseract')
                                    <button class="btn btn-sm btn-outline-primary" onclick="showLanguageInstall()">
                                        <i class="bi bi-translate me-1"></i>{{ __('super-ai-core::integrations.add_language') }}
                                    </button>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

@push('scripts')
<script>
var csrfToken = '{{ csrf_token() }}';

// ── Batch Check ──
document.getElementById('btn-batch-check')?.addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>{{ __("super-ai-core::integrations.batch_checking") }}';

    fetch('{{ route("super-ai-core.integrations.batchCheck") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        var ok = data.results.filter(r => r.ready);
        var errors = data.results.filter(r => !r.ready);
        var html = '<div style="text-align:left;max-height:400px;overflow-y:auto">';
        if (ok.length) {
            html += '<h6 class="text-success"><i class="bi bi-check-circle-fill me-1"></i>' + ok.length + ' {{ __("super-ai-core::integrations.batch_ok") }}</h6>';
            html += '<ul class="list-unstyled mb-3" style="font-size:.85rem">';
            ok.forEach(r => html += '<li><i class="bi bi-check text-success me-1"></i>' + r.name + '</li>');
            html += '</ul>';
        }
        if (errors.length) {
            html += '<h6 class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>' + errors.length + ' {{ __("super-ai-core::integrations.batch_errors") }}</h6>';
            html += '<ul class="list-unstyled" style="font-size:.85rem">';
            errors.forEach(r => html += '<li><i class="bi bi-x text-danger me-1"></i><strong>' + r.name + '</strong>: ' + (r.message || 'Dependency missing') + '</li>');
            html += '</ul>';
        }
        html += '</div>';
        Swal.fire({ title: '{{ __("super-ai-core::integrations.batch_check_result") }}', html: html, icon: errors.length > 0 ? 'warning' : 'success' });
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-clipboard-check me-1"></i>{{ __("super-ai-core::integrations.batch_check") }}';
    });
});

// ── Batch Install ──
document.getElementById('btn-batch-install')?.addEventListener('click', function() {
    var btn = this;
    Swal.fire({
        title: '{{ __("super-ai-core::integrations.batch_install_confirm") }}',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-download me-1"></i>{{ __("super-ai-core::integrations.batch_install") }}',
    }).then(result => {
        if (!result.isConfirmed) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>{{ __("super-ai-core::integrations.batch_installing") }}';

        var controller = new AbortController();
        var timeoutId = setTimeout(() => controller.abort(), 600000);

        fetch('{{ route("super-ai-core.integrations.batchInstall") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            signal: controller.signal
        })
        .then(r => { clearTimeout(timeoutId); if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(data => {
            var ok = data.results.filter(r => r.success);
            var fail = data.results.filter(r => !r.success);
            var html = '<div style="text-align:left;max-height:400px;overflow-y:auto">';
            if (ok.length) {
                html += '<h6 class="text-success">' + ok.length + ' {{ __("super-ai-core::integrations.batch_installed_ok") }}</h6>';
                html += '<ul class="list-unstyled mb-3" style="font-size:.85rem">';
                ok.forEach(r => html += '<li><i class="bi bi-check text-success me-1"></i>' + r.name + '</li>');
                html += '</ul>';
            }
            if (fail.length) {
                html += '<h6 class="text-danger">' + fail.length + ' {{ __("super-ai-core::integrations.batch_install_failed") }}</h6>';
                html += '<ul class="list-unstyled" style="font-size:.85rem">';
                fail.forEach(r => html += '<li><i class="bi bi-x text-danger me-1"></i><strong>' + r.name + '</strong>: ' + r.message + '</li>');
                html += '</ul>';
            }
            if (!data.results.length) html += '<p>{{ __("super-ai-core::integrations.batch_nothing") }}</p>';
            html += '</div>';
            Swal.fire({ title: '{{ __("super-ai-core::integrations.batch_install_result") }}', html: html, icon: fail.length > 0 ? 'warning' : 'success' })
                .then(() => location.reload());
        })
        .catch(err => {
            clearTimeout(timeoutId);
            var msg = err.name === 'AbortError' ? '{{ __("super-ai-core::integrations.batch_timeout") }}' : (err.message || 'Network error');
            Swal.fire({ icon: 'error', title: msg });
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-download me-1"></i>{{ __("super-ai-core::integrations.batch_install") }}';
        });
    });
});

// ── System Tools ──
function showInstallCommands(toolKey) {
    fetch(`{{ url(config('super-ai-core.route.prefix', 'super-ai-core') . '/integrations/system-tools') }}/${toolKey}/commands`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                Swal.fire({ icon: 'error', title: '{{ __("super-ai-core::integrations.error") }}', text: data.error });
                return;
            }
            var html = '<div class="text-start">';
            if (data.commands && data.commands.length) {
                html += '<p class="mb-3">{{ __("super-ai-core::integrations.run_commands") }}:</p>';
                html += '<div class="bg-dark text-light p-3 rounded mb-3" style="font-family:monospace;font-size:.9rem">';
                data.commands.forEach(cmd => html += `<div class="mb-2">${escapeHtml(cmd)}</div>`);
                html += '</div>';
                html += '<div class="alert alert-info mb-0"><i class="bi bi-info-circle me-2"></i>{{ __("super-ai-core::integrations.copy_paste_terminal") }}</div>';
            }
            if (data.manual_url) {
                html += `<p class="mt-3">{{ __("super-ai-core::integrations.manual_install") }}: <a href="${data.manual_url}" target="_blank">${data.manual_url}</a></p>`;
            }
            html += '</div>';
            Swal.fire({ title: `{{ __("super-ai-core::integrations.install") }} ${toolKey}`, html: html, width: '600px', confirmButtonText: '{{ __("super-ai-core::integrations.close") }}' });
        })
        .catch(err => Swal.fire({ icon: 'error', title: '{{ __("super-ai-core::integrations.error") }}', text: err.message }));
}

function showLanguageInstall() {
    var langs = {
        'chi_sim': '{{ __("super-ai-core::integrations.lang_chinese_simplified") }}',
        'chi_tra': '{{ __("super-ai-core::integrations.lang_chinese_traditional") }}',
        'jpn': '{{ __("super-ai-core::integrations.lang_japanese") }}',
        'kor': '{{ __("super-ai-core::integrations.lang_korean") }}',
        'fra': '{{ __("super-ai-core::integrations.lang_french") }}',
        'deu': '{{ __("super-ai-core::integrations.lang_german") }}',
        'spa': '{{ __("super-ai-core::integrations.lang_spanish") }}',
        'rus': '{{ __("super-ai-core::integrations.lang_russian") }}',
        'ara': '{{ __("super-ai-core::integrations.lang_arabic") }}',
    };
    var options = '';
    for (var code in langs) options += `<option value="${code}">${langs[code]}</option>`;

    Swal.fire({
        title: '{{ __("super-ai-core::integrations.install_language_pack") }}',
        html: `<select id="language-select" class="form-select"><option value="">{{ __("super-ai-core::integrations.select_language") }}</option>${options}</select>`,
        showCancelButton: true,
        confirmButtonText: '{{ __("super-ai-core::integrations.install") }}',
        cancelButtonText: '{{ __("super-ai-core::integrations.cancel") }}',
        preConfirm: () => {
            var lang = document.getElementById('language-select').value;
            if (!lang) { Swal.showValidationMessage('{{ __("super-ai-core::integrations.please_select_language") }}'); return false; }
            return lang;
        }
    }).then(result => {
        if (!result.isConfirmed) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = `{{ url(config('super-ai-core.route.prefix', 'super-ai-core') . '/integrations/system-tools/tesseract/language') }}/${result.value}`;
        var token = document.createElement('input');
        token.type = 'hidden'; token.name = '_token'; token.value = csrfToken;
        form.appendChild(token);
        document.body.appendChild(form);
        form.submit();
    });
}

function escapeHtml(text) {
    var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ── Individual Test ──
document.querySelectorAll('.btn-test').forEach(btn => {
    btn.addEventListener('click', function() {
        var url = this.dataset.url;
        var button = this;
        var origHtml = button.innerHTML;
        button.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>';
        button.disabled = true;
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => Swal.fire({
                toast: true, position: 'top-end',
                icon: data.success ? 'success' : 'error',
                title: data.success ? '{{ __("super-ai-core::integrations.test_ready") }}' : (data.message || 'Error'),
                showConfirmButton: false, timer: 2500
            }))
            .catch(() => Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Network error', showConfirmButton: false, timer: 2500 }))
            .finally(() => { button.innerHTML = origHtml; button.disabled = false; });
    });
});
</script>
<style>
.spin { animation: spin 1s linear infinite; display: inline-block; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.mcp-card { transition: border-color .2s; }
.mcp-card:hover { border-color: var(--tf-primary) !important; }
.mcp-icon { width: 36px; height: 36px; font-size: 1rem; }
</style>
@endpush
@endsection
