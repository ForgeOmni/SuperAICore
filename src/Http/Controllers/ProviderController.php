<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Models\AiProvider;
use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Services\ClaudeModelResolver;
use SuperAICore\Services\CliStatusDetector;
use SuperAICore\Support\SuperAgentDetector;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * AI Provider CRUD — the "Execution Engine" management page.
 *
 * Host app wires authorization middleware via config('super-ai-core.route.middleware').
 */
class ProviderController extends Controller
{
    public function index()
    {
        $providers = AiProvider::where('scope', 'global')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $cliStatuses = CliStatusDetector::all();
        $defaultBackend = IntegrationConfig::getValue('ai_execution', 'default_backend') ?: 'claude';

        // Hide SuperAgent entirely when the SDK is not installed.
        $backends = ['claude', 'codex'];
        if (SuperAgentDetector::isAvailable()) {
            $backends[] = 'superagent';
        }

        // Per-backend runtime on/off — persisted in IntegrationConfig, read by
        // BackendRegistry + Dispatcher so providers under a disabled backend
        // cannot be used.
        $backendDisabled = [];
        foreach ($backends as $be) {
            $backendDisabled[$be] = self::isBackendDisabled($be);
        }

        // Group providers by backend; UI prepends a synthetic "Built-in" per backend.
        $providersByBackend = [];
        foreach ($backends as $be) {
            $providersByBackend[$be] = $providers->where('backend', $be)->values();
        }

        // Matrix used by the "New provider" modal to narrow the type select.
        $backendTypes = array_intersect_key(AiProvider::BACKEND_TYPES, array_flip($backends));

        return view('super-ai-core::providers.index', compact(
            'providers', 'providersByBackend', 'cliStatuses', 'defaultBackend',
            'backends', 'backendTypes', 'backendDisabled'
        ));
    }

    public function toggleBackend(Request $request)
    {
        $request->validate([
            'backend' => 'required|in:' . implode(',', $this->availableBackends()),
            'enabled' => 'required|boolean',
        ]);

        IntegrationConfig::setValue(
            'ai_execution',
            'backend_disabled.' . $request->input('backend'),
            $request->boolean('enabled') ? '0' : '1'
        );

        return back()->with('success', __('super-ai-core::messages.backend_state_saved'));
    }

    /**
     * Shared read path used by the controller, the BackendRegistry, and the
     * Dispatcher to gate runtime usage of a backend.
     */
    public static function isBackendDisabled(string $backend): bool
    {
        return IntegrationConfig::getValue('ai_execution', 'backend_disabled.' . $backend) === '1';
    }

    /**
     * Activate the built-in (local CLI login) for a backend — deactivates
     * all external providers in that backend scope.
     */
    public function activateBuiltin(Request $request)
    {
        $request->validate(['backend' => 'required|in:' . implode(',', $this->availableBackends())]);
        $backend = $request->input('backend');

        AiProvider::where('scope', 'global')
            ->where('backend', $backend)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return back()->with('success', __('super-ai-core::messages.provider_activated'));
    }

    /**
     * Test a built-in backend (local claude/codex CLI login) with a tiny prompt.
     */
    public function testBuiltin(Request $request, \SuperAICore\Services\Dispatcher $dispatcher)
    {
        $request->validate(['backend' => 'required|in:claude,codex']);
        $backendName = $request->input('backend') === 'claude' ? 'claude_cli' : 'codex_cli';

        try {
            $result = $dispatcher->dispatch([
                'prompt' => 'Reply with exactly: OK',
                'max_tokens' => 10,
                'backend' => $backendName,
                'provider_config' => ['type' => 'builtin'],
            ]);

            if ($result && !empty($result['text'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connected. ' . mb_substr(trim($result['text']), 0, 100),
                ]);
            }
            return response()->json(['success' => false, 'message' => 'No response — CLI may not be installed or signed in.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function saveDefaultBackend(Request $request)
    {
        $request->validate(['backend' => 'required|in:' . implode(',', $this->availableBackends())]);
        IntegrationConfig::setValue('ai_execution', 'default_backend', $request->input('backend'));
        return back()->with('success', __('super-ai-core::messages.default_backend_saved'));
    }

    public function cliStatus()
    {
        return response()->json(CliStatusDetector::all());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        AiProvider::create(array_merge($data, [
            'scope' => 'global',
            'scope_id' => null,
        ]));
        return back()->with('success', __('super-ai-core::messages.provider_created'));
    }

    public function update(Request $request, AiProvider $provider)
    {
        $data = $this->validated($request, $provider);

        // Don't overwrite api_key if not provided (preserves existing encrypted key)
        if (empty($data['api_key'])) {
            unset($data['api_key']);
        }

        $provider->update($data);
        return back()->with('success', __('super-ai-core::messages.provider_saved'));
    }

    public function destroy(AiProvider $provider)
    {
        $provider->delete();
        return back()->with('success', __('super-ai-core::messages.provider_deleted'));
    }

    public function activate(AiProvider $provider)
    {
        $provider->activate();
        return back()->with('success', __('super-ai-core::messages.provider_activated'));
    }

    public function deactivate(AiProvider $provider)
    {
        $provider->deactivate();
        return back()->with('success', __('super-ai-core::messages.provider_deactivated'));
    }

    /**
     * Fetch available models from the provider's API.
     */
    public function models(AiProvider $provider)
    {
        if (in_array($provider->type, [AiProvider::TYPE_ANTHROPIC, AiProvider::TYPE_ANTHROPIC_PROXY])) {
            try {
                $models = $this->fetchAnthropicModels($provider);
                if ($models) return response()->json(['models' => $models]);
            } catch (\Throwable $e) {
                // fall through to fallback
            }
        }

        if (in_array($provider->type, [AiProvider::TYPE_OPENAI, AiProvider::TYPE_OPENAI_COMPATIBLE])) {
            try {
                $models = $this->fetchOpenAiModels($provider);
                if ($models) return response()->json(['models' => $models]);
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return response()->json(['models' => $this->fallbackModels($provider->type)]);
    }

    /**
     * Test provider connection with a tiny prompt via Dispatcher.
     */
    public function test(AiProvider $provider, \SuperAICore\Services\Dispatcher $dispatcher)
    {
        try {
            $result = $dispatcher->dispatch([
                'prompt' => 'Reply with exactly: OK',
                'max_tokens' => 10,
                'provider_id' => $provider->id,
            ]);

            if ($result && !empty($result['text'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connected. ' . mb_substr($result['text'], 0, 100),
                ]);
            }
            return response()->json(['success' => false, 'message' => 'No response']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ─── Helpers ───

    protected function availableBackends(): array
    {
        $backends = [AiProvider::BACKEND_CLAUDE, AiProvider::BACKEND_CODEX];
        if (SuperAgentDetector::isAvailable()) {
            $backends[] = AiProvider::BACKEND_SUPERAGENT;
        }
        return $backends;
    }

    protected function validated(Request $request, ?AiProvider $provider = null): array
    {
        $backends = $this->availableBackends();

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'backend' => ($provider ? 'nullable' : 'required') . '|in:' . implode(',', $backends),
            'type' => ($provider ? 'nullable' : 'required') . '|in:' . implode(',', array_keys(AiProvider::TYPES)),
            'api_key' => 'nullable|string|max:500',
            'base_url' => 'nullable|url|max:500',
            'extra_config' => 'nullable|array',
            'extra_config.region' => 'nullable|string|max:100',
            'extra_config.access_key_id' => 'nullable|string|max:255',
            'extra_config.secret_access_key' => 'nullable|string|max:255',
            'extra_config.project_id' => 'nullable|string|max:255',
        ]);

        // Enforce the backend → type matrix (same combinations SuperTeam used).
        $backend = $data['backend'] ?? $provider?->backend;
        $type = $data['type'] ?? $provider?->type;
        if ($backend && $type) {
            $allowed = AiProvider::typesForBackend($backend);
            if (!in_array($type, $allowed, true)) {
                abort(422, "Type '{$type}' is not allowed for backend '{$backend}'. Allowed: " . implode(', ', $allowed));
            }
        }

        return $data;
    }

    protected function fetchAnthropicModels(AiProvider $provider): array
    {
        $client = new Client(['timeout' => 10]);
        $response = $client->get(rtrim($provider->getApiBaseUrl(), '/') . '/v1/models', [
            'headers' => [
                'x-api-key' => $provider->decrypted_api_key,
                'anthropic-version' => '2023-06-01',
            ],
        ]);
        $data = json_decode((string) $response->getBody(), true);
        $models = [];
        foreach (($data['data'] ?? []) as $m) {
            $models[] = [
                'id' => $m['id'] ?? '',
                'display_name' => $m['display_name'] ?? $m['id'] ?? '',
            ];
        }
        return $models;
    }

    protected function fetchOpenAiModels(AiProvider $provider): array
    {
        $client = new Client(['timeout' => 10]);
        $baseUrl = $provider->base_url ?: 'https://api.openai.com';
        $response = $client->get(rtrim($baseUrl, '/') . '/v1/models', [
            'headers' => [
                'Authorization' => 'Bearer ' . $provider->decrypted_api_key,
            ],
        ]);
        $data = json_decode((string) $response->getBody(), true);
        $models = [];
        foreach (($data['data'] ?? []) as $m) {
            $models[] = [
                'id' => $m['id'] ?? '',
                'display_name' => $m['id'] ?? '',
            ];
        }
        return $models;
    }

    protected function fallbackModels(string $type): array
    {
        if (str_starts_with($type, 'anthropic') || $type === AiProvider::TYPE_BEDROCK || $type === AiProvider::TYPE_VERTEX) {
            // Derived from ClaudeModelResolver so new Claude generations
            // only need to be added in one place.
            return array_map(
                fn ($m) => ['id' => $m['slug'], 'display_name' => $m['display_name']],
                ClaudeModelResolver::catalog(),
            );
        }
        if (str_starts_with($type, 'openai')) {
            return [
                ['id' => 'gpt-4o', 'display_name' => 'GPT-4o'],
                ['id' => 'gpt-4o-mini', 'display_name' => 'GPT-4o mini'],
            ];
        }
        return [];
    }
}
