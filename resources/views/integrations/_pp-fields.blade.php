{{-- Pre-process configuration form fields (reusable for add/edit capability modals) --}}
{{-- @param $prefix: 'add' or 'edit' — used to namespace element IDs --}}
<label class="form-label">{{ __('super-ai-core::integrations.ai_cap_preprocess') }}</label>
<select class="form-select form-select-sm mb-2" id="{{ $prefix }}PpType" onchange="ppTypeChanged('{{ $prefix }}')">
    <option value="">— {{ __('super-ai-core::integrations.ai_cap_pp_none') }} —</option>
    <option value="file_analysis">{{ __('super-ai-core::integrations.ai_cap_pp_file_analysis') }}</option>
    <option value="text_transform">{{ __('super-ai-core::integrations.ai_cap_pp_text_transform') }}</option>
    <option value="content_generate">{{ __('super-ai-core::integrations.ai_cap_pp_content_generate') }}</option>
    <option value="image_generate">{{ __('super-ai-core::integrations.ai_cap_pp_image_generate') }}</option>
    <option value="audio_transcribe">{{ __('super-ai-core::integrations.ai_cap_pp_audio_transcribe') }}</option>
    <option value="text_to_speech">{{ __('super-ai-core::integrations.ai_cap_pp_text_to_speech') }}</option>
</select>
<div id="{{ $prefix }}PpDesc" class="alert alert-light py-1 px-2 mb-2" style="font-size:.7rem;display:none"></div>

{{-- file_analysis fields --}}
<div id="{{ $prefix }}PpF_file_analysis" class="pp-type-fields" style="display:none">
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_extensions') }}</label>
        <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpExt" placeholder="jpg, jpeg, png, gif, webp" value="jpg, jpeg, png, gif, webp">
    </div>
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_prompt') }}</label>
        <textarea class="form-control form-control-sm" id="{{ $prefix }}PpPrompt" rows="3" style="font-size:.8rem" placeholder="{{ __('super-ai-core::integrations.ai_cap_pp_prompt_hint') }}"></textarea>
    </div>
    <div>
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_cache') }}</label>
        <select class="form-select form-select-sm" id="{{ $prefix }}PpCache">
            <option value="">— {{ __('super-ai-core::integrations.ai_cap_pp_no_cache') }} —</option>
            <option value="photo_descriptions">photo_descriptions</option>
        </select>
    </div>
</div>

{{-- text_transform fields --}}
<div id="{{ $prefix }}PpF_text_transform" class="pp-type-fields" style="display:none">
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_extensions') }}</label>
        <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpTtExt" placeholder="md, txt" value="md, txt">
    </div>
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_prompt') }} <code class="text-muted" style="font-size:.7rem">{content}</code></label>
        <textarea class="form-control form-control-sm" id="{{ $prefix }}PpTtPrompt" rows="3" style="font-size:.8rem" placeholder="Translate the following to French:\n\n{content}"></textarea>
    </div>
    <div class="row g-2">
        <div class="col-6">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_output_suffix') }}</label>
            <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpTtSuffix" placeholder=".fr" value=".out">
        </div>
        <div class="col-6">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_max_tokens') }}</label>
            <input type="number" class="form-control form-control-sm" id="{{ $prefix }}PpTtMaxTokens" value="4096">
        </div>
    </div>
</div>

{{-- content_generate fields --}}
<div id="{{ $prefix }}PpF_content_generate" class="pp-type-fields" style="display:none">
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_prompt') }}</label>
        <textarea class="form-control form-control-sm" id="{{ $prefix }}PpCgPrompt" rows="3" style="font-size:.8rem" placeholder="Generate a summary..."></textarea>
    </div>
    <div class="row g-2">
        <div class="col-6">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_output_file') }}</label>
            <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpCgFile" placeholder="generated.md" value="generated.md">
        </div>
        <div class="col-6">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_max_tokens') }}</label>
            <input type="number" class="form-control form-control-sm" id="{{ $prefix }}PpCgMaxTokens" value="2048">
        </div>
    </div>
</div>

{{-- image_generate fields --}}
<div id="{{ $prefix }}PpF_image_generate" class="pp-type-fields" style="display:none">
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_prompt') }}</label>
        <textarea class="form-control form-control-sm" id="{{ $prefix }}PpIgPrompt" rows="3" style="font-size:.8rem" placeholder="A professional infographic about..."></textarea>
    </div>
    <div class="row g-2">
        <div class="col-4">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_output_file') }}</label>
            <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpIgFile" placeholder="cover.png" value="generated.png">
        </div>
        <div class="col-4">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_size') }}</label>
            <select class="form-select form-select-sm" id="{{ $prefix }}PpIgSize">
                <option value="1024x1024" selected>1024x1024</option>
                <option value="1792x1024">1792x1024</option>
                <option value="1024x1792">1024x1792</option>
                <option value="512x512">512x512</option>
                <option value="256x256">256x256</option>
            </select>
        </div>
        <div class="col-4">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_count') }}</label>
            <input type="number" class="form-control form-control-sm" id="{{ $prefix }}PpIgN" value="1" min="1" max="4">
        </div>
    </div>
</div>

{{-- audio_transcribe fields --}}
<div id="{{ $prefix }}PpF_audio_transcribe" class="pp-type-fields" style="display:none">
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_extensions') }}</label>
        <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpAtExt" placeholder="mp3, wav, m4a, flac" value="mp3, wav, m4a, flac, ogg">
    </div>
    <div class="row g-2">
        <div class="col-6">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_language') }}</label>
            <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpAtLang" placeholder="en / zh / auto">
        </div>
        <div class="col-6">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_output_suffix') }}</label>
            <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpAtSuffix" value=".txt">
        </div>
    </div>
</div>

{{-- text_to_speech fields --}}
<div id="{{ $prefix }}PpF_text_to_speech" class="pp-type-fields" style="display:none">
    <div class="mb-2">
        <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_extensions') }}</label>
        <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpTsExt" placeholder="md, txt" value="md, txt">
    </div>
    <div class="row g-2">
        <div class="col-4">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_voice') }}</label>
            <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpTsVoice" placeholder="alloy" value="alloy">
        </div>
        <div class="col-4">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_audio_format') }}</label>
            <select class="form-select form-select-sm" id="{{ $prefix }}PpTsFmt">
                <option value="mp3" selected>MP3</option>
                <option value="wav">WAV</option>
                <option value="opus">Opus</option>
                <option value="flac">FLAC</option>
            </select>
        </div>
        <div class="col-4">
            <label class="form-label" style="font-size:.8rem">{{ __('super-ai-core::integrations.ai_cap_pp_output_suffix') }}</label>
            <input type="text" class="form-control form-control-sm" id="{{ $prefix }}PpTsSuffix" value=".mp3">
        </div>
    </div>
</div>

<input type="hidden" name="pre_process" id="{{ $prefix }}PpJson">
