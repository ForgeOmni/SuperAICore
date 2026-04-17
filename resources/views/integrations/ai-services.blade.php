@extends('super-ai-core::layouts.app')
@section('title', __('super-ai-core::integrations.ai_services_title'))

@section('content')
<div class="container-fluid py-4" style="max-width:1000px">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-diagram-3 me-2"></i>{{ __('super-ai-core::integrations.ai_services_title') }}</h4>
            <p class="text-muted mb-0" style="font-size:.875rem">{{ __('super-ai-core::integrations.ai_services_subtitle') }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('super-ai-core.services.routing') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-signpost-split me-1"></i>{{ __('super-ai-core::integrations.ai_route_title') }}
            </a>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCapModal">
                <i class="bi bi-plus-lg me-1"></i>{{ __('super-ai-core::integrations.ai_cap_add') }}
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2" style="font-size:.875rem">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" style="font-size:.7rem"></button>
        </div>
    @endif

    {{-- ═══ Capabilities + nested Services ═══ --}}
    @forelse($capabilities as $cap)
    <div class="card mb-3" style="{{ !$cap->is_active ? 'opacity:.6' : '' }}">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <div class="d-flex align-items-center gap-2" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#capServices-{{ $cap->id }}">
                <i class="bi bi-chevron-right collapse-chevron" id="chevron-{{ $cap->id }}" style="font-size:.7rem;transition:transform .2s"></i>
                <i class="bi bi-lightning{{ $cap->is_active ? '-fill text-warning' : ' text-muted' }}"></i>
                <div>
                    <span class="fw-semibold">{{ __('super-ai-core::integrations.ai_cap_type_' . $cap->slug) !== 'integrations.ai_cap_type_' . $cap->slug ? __('super-ai-core::integrations.ai_cap_type_' . $cap->slug) : $cap->name }}</span>
                    <code class="ms-2 text-muted" style="font-size:.7rem">{{ $cap->slug }}</code>
                    @if($cap->pre_process)
                        <span class="badge bg-info bg-opacity-10 text-info ms-1" style="font-size:.6rem">{{ $cap->pre_process['type'] ?? '—' }}</span>
                    @endif
                    <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1" style="font-size:.6rem">{{ $services->where('capability_id', $cap->id)->count() }} {{ __('super-ai-core::integrations.ai_svc_count') }}</span>
                </div>
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary" style="font-size:.7rem" onclick="editCapability({{ json_encode($cap) }})">
                    <i class="bi bi-pencil"></i>
                </button>
                <form action="{{ route('super-ai-core.capabilities.toggle', $cap) }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm {{ $cap->is_active ? 'btn-outline-success' : 'btn-outline-secondary' }}" style="font-size:.7rem">
                        <i class="bi {{ $cap->is_active ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                    </button>
                </form>
                <form action="{{ route('super-ai-core.capabilities.destroy', $cap) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete capability and all its services?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:.7rem"><i class="bi bi-trash3"></i></button>
                </form>
            </div>
        </div>

        @if($cap->description)
            <div class="px-3 py-1" style="font-size:.8rem;color:var(--tf-text-muted);background:#f8fafc">{{ $cap->description }}</div>
        @endif

        @php $capServices = $services->where('capability_id', $cap->id); @endphp

        {{-- Collapsible services section --}}
        <div class="collapse" id="capServices-{{ $cap->id }}">
            <div class="card-body p-0">
                @if($capServices->isNotEmpty())
                <table class="table table-sm mb-0" style="font-size:.8125rem">
                    <thead><tr class="text-muted" style="font-size:.75rem"><th style="padding-left:1.5rem">{{ __('super-ai-core::integrations.ai_svc_name') }}</th><th>{{ __('super-ai-core::integrations.ai_svc_protocol') }}</th><th>{{ __('super-ai-core::integrations.ai_svc_endpoint') }}</th><th>{{ __('super-ai-core::integrations.ai_svc_model') }}</th><th style="width:160px"></th></tr></thead>
                    <tbody>
                    @foreach($capServices as $svc)
                        <tr class="{{ !$svc->is_active ? 'text-muted' : '' }}">
                            <td style="padding-left:1.5rem" class="fw-semibold">{{ $svc->name }}</td>
                            <td><span class="badge {{ $svc->protocol === 'anthropic' ? 'bg-primary' : 'bg-success' }} bg-opacity-10 {{ $svc->protocol === 'anthropic' ? 'text-primary' : 'text-success' }}" style="font-size:.65rem">{{ $svc->protocol }}</span></td>
                            <td><code style="font-size:.75rem">{{ $svc->base_url }}</code></td>
                            <td><code style="font-size:.75rem">{{ $svc->model }}</code></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-info" style="font-size:.65rem" onclick="testService({{ $svc->id }}, this)">
                                    <i class="bi bi-wifi"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-primary" style="font-size:.65rem" onclick="editService({{ json_encode($svc) }})"><i class="bi bi-pencil"></i></button>
                                <form action="{{ route('super-ai-core.ai-service.toggle', $svc) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm {{ $svc->is_active ? 'btn-outline-success' : 'btn-outline-secondary' }}" style="font-size:.65rem">
                                        <i class="bi {{ $svc->is_active ? 'bi-check-circle-fill' : 'bi-circle' }}"></i>
                                    </button>
                                </form>
                                <form action="{{ route('super-ai-core.ai-service.destroy', $svc) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:.65rem"><i class="bi bi-trash3"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
                <div class="px-3 py-2 border-top" style="background:#fafbfc">
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:.75rem" onclick="openAddService({{ $cap->id }}, '{{ addslashes($cap->name) }}')">
                        <i class="bi bi-plus me-1"></i>{{ __('super-ai-core::integrations.ai_svc_add') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @empty
    <div class="text-center text-muted py-5">
        <i class="bi bi-diagram-3" style="font-size:2rem"></i>
        <p class="mt-2">{{ __('super-ai-core::integrations.ai_cap_add') }}</p>
    </div>
    @endforelse
</div>

{{-- ═══ Add Capability Modal ═══ --}}
<div class="modal fade" id="addCapModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--tf-radius)">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-lightning me-1"></i>{{ __('super-ai-core::integrations.ai_cap_add') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.75rem"></button></div>
            <form action="{{ route('super-ai-core.capabilities.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-4"><label class="form-label">{{ __('super-ai-core::integrations.ai_cap_slug') }}</label>
                            <select class="form-select form-select-sm" name="slug" id="addCapSlug" required onchange="addSlugChanged()">
                                <option value="">— {{ __('super-ai-core::integrations.ai_cap_slug_select') }} —</option>
                                <option value="vision">{{ __('super-ai-core::integrations.ai_cap_type_vision') }}</option>
                                <option value="text_transform">{{ __('super-ai-core::integrations.ai_cap_type_text_transform') }}</option>
                                <option value="content_generate">{{ __('super-ai-core::integrations.ai_cap_type_content_generate') }}</option>
                                <option value="image_generate">{{ __('super-ai-core::integrations.ai_cap_type_image_generate') }}</option>
                                <option value="audio_transcribe">{{ __('super-ai-core::integrations.ai_cap_type_audio_transcribe') }}</option>
                                <option value="text_to_speech">{{ __('super-ai-core::integrations.ai_cap_type_text_to_speech') }}</option>
                            </select>
                        </div>
                        <div class="col-8"><label class="form-label">{{ __('super-ai-core::integrations.ai_cap_name') }}</label><input type="text" class="form-control form-control-sm" name="name" id="addCapName" required placeholder="Vision"></div>
                        <div class="col-12"><label class="form-label">{{ __('super-ai-core::integrations.ai_cap_desc') }}</label><textarea class="form-control form-control-sm" name="description" rows="2" placeholder="Image analysis and description"></textarea></div>
                        <div class="col-12">
                            @include('super-ai-core::integrations._pp-fields', ['prefix' => 'add'])
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('tasks.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="buildPreProcessJson('add')">{{ __('super-ai-core::integrations.ai_cap_add') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ═══ Edit Capability Modal ═══ --}}
<div class="modal fade" id="editCapModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--tf-radius)">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-pencil me-1"></i>{{ __('super-ai-core::integrations.ai_cap_edit') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.75rem"></button></div>
            <form id="editCapForm" method="POST">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label">{{ __('super-ai-core::integrations.ai_cap_name') }}</label><input type="text" class="form-control form-control-sm" name="name" id="editCapName" required></div>
                        <div class="col-12"><label class="form-label">{{ __('super-ai-core::integrations.ai_cap_desc') }}</label><textarea class="form-control form-control-sm" name="description" id="editCapDesc" rows="2"></textarea></div>
                        <div class="col-12">
                            @include('super-ai-core::integrations._pp-fields', ['prefix' => 'edit'])
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('tasks.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="buildPreProcessJson('edit')">{{ __('super-ai-core::integrations.ai_cap_edit') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ═══ Add Service Modal ═══ --}}
<div class="modal fade" id="addSvcModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--tf-radius)">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-hdd-network me-1"></i>{{ __('super-ai-core::integrations.ai_svc_add') }} — <span id="addSvcCapName"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.75rem"></button></div>
            <form action="{{ route('super-ai-core.ai-service.store') }}" method="POST">
                @csrf
                <input type="hidden" name="capability_id" id="addSvcCapId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_name') }}</label><input type="text" class="form-control form-control-sm" name="name" required placeholder="Local Qwen Vision"></div>
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_protocol') }}</label>
                            <select class="form-select form-select-sm" name="protocol" required>
                                <option value="anthropic">Anthropic</option>
                                <option value="openai">OpenAI</option>
                                <option value="minimax">MiniMax</option>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_endpoint') }}</label><input type="text" class="form-control form-control-sm" name="base_url" required placeholder="http://127.0.0.1:8888"></div>
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_model') }}</label><input type="text" class="form-control form-control-sm" name="model" required placeholder="Qwen3.5-2B-6bit"></div>
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_api_key') }}</label><input type="password" class="form-control form-control-sm" name="api_key" placeholder="sk-..."><small class="text-muted" style="font-size:.7rem">{{ __('super-ai-core::integrations.ai_svc_api_key_hint') }}</small></div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('tasks.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('super-ai-core::integrations.ai_svc_add') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ═══ Edit Service Modal ═══ --}}
<div class="modal fade" id="editSvcModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--tf-radius)">
            <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-pencil me-1"></i>{{ __('super-ai-core::integrations.ai_svc_edit') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.75rem"></button></div>
            <form id="editSvcForm" method="POST">
                @csrf @method('PUT')
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_name') }}</label><input type="text" class="form-control form-control-sm" name="name" id="editSvcName" required></div>
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_protocol') }}</label>
                            <select class="form-select form-select-sm" name="protocol" id="editSvcProtocol" required>
                                <option value="anthropic">Anthropic</option>
                                <option value="openai">OpenAI</option>
                                <option value="minimax">MiniMax</option>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_endpoint') }}</label><input type="text" class="form-control form-control-sm" name="base_url" id="editSvcUrl" required></div>
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_model') }}</label><input type="text" class="form-control form-control-sm" name="model" id="editSvcModel" required></div>
                        <div class="col-6"><label class="form-label">{{ __('super-ai-core::integrations.ai_svc_api_key') }}</label><input type="password" class="form-control form-control-sm" name="api_key" placeholder="Leave empty to keep current"></div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('tasks.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('super-ai-core::integrations.ai_svc_edit') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Slug → default name & pre-process type mapping
const slugDefaults = {
    vision:            { name: 'Vision',            ppType: 'file_analysis' },
    text_transform:    { name: 'Text Transform',    ppType: 'text_transform' },
    content_generate:  { name: 'Content Generate',  ppType: 'content_generate' },
    image_generate:    { name: 'Image Generate',    ppType: 'image_generate' },
    audio_transcribe:  { name: 'Audio Transcribe',  ppType: 'audio_transcribe' },
    text_to_speech:    { name: 'Text to Speech',    ppType: 'text_to_speech' },
};

function addSlugChanged() {
    const slug = document.getElementById('addCapSlug').value;
    const def = slugDefaults[slug];
    if (def) {
        const nameInput = document.getElementById('addCapName');
        if (!nameInput.value || Object.values(slugDefaults).some(d => d.name === nameInput.value)) {
            nameInput.value = def.name;
        }
        document.getElementById('addPpType').value = def.ppType;
        ppTypeChanged('add');
    }
}

function openAddService(capId, capName) {
    document.getElementById('addSvcCapId').value = capId;
    document.getElementById('addSvcCapName').textContent = capName;
    new bootstrap.Modal(document.getElementById('addSvcModal')).show();
}
// ─── Pre-process type descriptions ───
const ppDescriptions = {
    file_analysis: @json(__('super-ai-core::integrations.ai_cap_pp_file_analysis_desc')),
    text_transform: @json(__('super-ai-core::integrations.ai_cap_pp_text_transform_desc')),
    content_generate: @json(__('super-ai-core::integrations.ai_cap_pp_content_generate_desc')),
    image_generate: @json(__('super-ai-core::integrations.ai_cap_pp_image_generate_desc')),
    audio_transcribe: @json(__('super-ai-core::integrations.ai_cap_pp_audio_transcribe_desc')),
    text_to_speech: @json(__('super-ai-core::integrations.ai_cap_pp_text_to_speech_desc')),
};

function ppTypeChanged(prefix) {
    const type = document.getElementById(prefix + 'PpType').value;
    // Hide all type-specific fields
    document.querySelectorAll('#' + prefix + 'PpType').forEach(() => {});
    ['file_analysis','text_transform','content_generate','image_generate','audio_transcribe','text_to_speech'].forEach(t => {
        const el = document.getElementById(prefix + 'PpF_' + t);
        if (el) el.style.display = (t === type) ? '' : 'none';
    });
    // Show/hide description
    const descEl = document.getElementById(prefix + 'PpDesc');
    if (type && ppDescriptions[type]) {
        descEl.textContent = ppDescriptions[type];
        descEl.style.display = '';
    } else {
        descEl.style.display = 'none';
    }
}

function editCapability(cap) {
    document.getElementById('editCapForm').action = '/integrations/ai-capabilities/' + cap.id;
    document.getElementById('editCapName').value = cap.name;
    document.getElementById('editCapDesc').value = cap.description || '';
    const pp = cap.pre_process || {};
    $('#editPpType').val(pp.type || '').trigger('change');
    ppTypeChanged('edit');
    // Populate type-specific fields
    if (pp.type === 'file_analysis') {
        document.getElementById('editPpExt').value = (pp.file_extensions || []).join(', ');
        document.getElementById('editPpPrompt').value = pp.prompt || '';
        $('#editPpCache').val(pp.cache || '').trigger('change');
    } else if (pp.type === 'text_transform') {
        document.getElementById('editPpTtExt').value = (pp.file_extensions || []).join(', ');
        document.getElementById('editPpTtPrompt').value = pp.prompt || '';
        document.getElementById('editPpTtSuffix').value = pp.output_suffix || '.out';
        document.getElementById('editPpTtMaxTokens').value = pp.max_tokens || 4096;
    } else if (pp.type === 'content_generate') {
        document.getElementById('editPpCgPrompt').value = pp.prompt || '';
        document.getElementById('editPpCgFile').value = pp.output_file || 'generated.md';
        document.getElementById('editPpCgMaxTokens').value = pp.max_tokens || 2048;
    } else if (pp.type === 'image_generate') {
        document.getElementById('editPpIgPrompt').value = pp.prompt || '';
        document.getElementById('editPpIgFile').value = pp.output_file || 'generated.png';
        $('#editPpIgSize').val(pp.size || '1024x1024').trigger('change');
        document.getElementById('editPpIgN').value = pp.n || 1;
    } else if (pp.type === 'audio_transcribe') {
        document.getElementById('editPpAtExt').value = (pp.file_extensions || []).join(', ');
        document.getElementById('editPpAtLang').value = pp.language || '';
        document.getElementById('editPpAtSuffix').value = pp.output_suffix || '.txt';
    } else if (pp.type === 'text_to_speech') {
        document.getElementById('editPpTsExt').value = (pp.file_extensions || []).join(', ');
        document.getElementById('editPpTsVoice').value = pp.voice || 'alloy';
        $('#editPpTsFmt').val(pp.output_format || 'mp3').trigger('change');
        document.getElementById('editPpTsSuffix').value = pp.output_suffix || '.mp3';
    }
    new bootstrap.Modal(document.getElementById('editCapModal')).show();
}

function buildPreProcessJson(prefix) {
    const type = document.getElementById(prefix + 'PpType').value;
    const jsonInput = document.getElementById(prefix + 'PpJson');
    if (!type) { jsonInput.value = ''; return; }

    const config = { type };
    const parseExt = (id) => document.getElementById(id).value.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
    const val = (id) => document.getElementById(id)?.value?.trim() || '';

    if (type === 'file_analysis') {
        const ext = parseExt(prefix + 'PpExt');
        if (ext.length) config.file_extensions = ext;
        if (val(prefix + 'PpPrompt')) config.prompt = val(prefix + 'PpPrompt');
        const cache = val(prefix + 'PpCache');
        if (cache) config.cache = cache;
    } else if (type === 'text_transform') {
        const ext = parseExt(prefix + 'PpTtExt');
        if (ext.length) config.file_extensions = ext;
        if (val(prefix + 'PpTtPrompt')) config.prompt = val(prefix + 'PpTtPrompt');
        config.output_suffix = val(prefix + 'PpTtSuffix') || '.out';
        config.max_tokens = parseInt(val(prefix + 'PpTtMaxTokens')) || 4096;
    } else if (type === 'content_generate') {
        if (val(prefix + 'PpCgPrompt')) config.prompt = val(prefix + 'PpCgPrompt');
        config.output_file = val(prefix + 'PpCgFile') || 'generated.md';
        config.max_tokens = parseInt(val(prefix + 'PpCgMaxTokens')) || 2048;
    } else if (type === 'image_generate') {
        if (val(prefix + 'PpIgPrompt')) config.prompt = val(prefix + 'PpIgPrompt');
        config.output_file = val(prefix + 'PpIgFile') || 'generated.png';
        config.size = val(prefix + 'PpIgSize') || '1024x1024';
        config.n = parseInt(val(prefix + 'PpIgN')) || 1;
    } else if (type === 'audio_transcribe') {
        const ext = parseExt(prefix + 'PpAtExt');
        if (ext.length) config.file_extensions = ext;
        const lang = val(prefix + 'PpAtLang');
        if (lang) config.language = lang;
        config.output_suffix = val(prefix + 'PpAtSuffix') || '.txt';
    } else if (type === 'text_to_speech') {
        const ext = parseExt(prefix + 'PpTsExt');
        if (ext.length) config.file_extensions = ext;
        config.voice = val(prefix + 'PpTsVoice') || 'alloy';
        config.output_format = val(prefix + 'PpTsFmt') || 'mp3';
        config.output_suffix = val(prefix + 'PpTsSuffix') || '.mp3';
    }

    jsonInput.value = JSON.stringify(config);
}

// Chevron rotation on collapse toggle
document.querySelectorAll('.collapse').forEach(el => {
    el.addEventListener('show.bs.collapse', () => {
        const id = el.id.replace('capServices-', '');
        const chevron = document.getElementById('chevron-' + id);
        if (chevron) chevron.style.transform = 'rotate(90deg)';
    });
    el.addEventListener('hide.bs.collapse', () => {
        const id = el.id.replace('capServices-', '');
        const chevron = document.getElementById('chevron-' + id);
        if (chevron) chevron.style.transform = '';
    });
});
function editService(svc) {
    document.getElementById('editSvcForm').action = '/integrations/ai-service/' + svc.id;
    document.getElementById('editSvcName').value = svc.name;
    $('#editSvcProtocol').val(svc.protocol).trigger('change');
    document.getElementById('editSvcModel').value = svc.model;
    document.getElementById('editSvcUrl').value = svc.base_url;
    new bootstrap.Modal(document.getElementById('editSvcModal')).show();
}
function testService(id, btn) {
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>';
    btn.disabled = true;
    fetch('/integrations/ai-service/' + id + '/test', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = data.success ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>';
            alert(data.success ? '✓ ' + data.message : '✗ ' + data.message);
            setTimeout(() => { btn.innerHTML = origHtml; btn.disabled = false; }, 3000);
        })
        .catch(err => { btn.innerHTML = origHtml; btn.disabled = false; alert('Request failed: ' + err.message); });
}
</script>
@endpush
