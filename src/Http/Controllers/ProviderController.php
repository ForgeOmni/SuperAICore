<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Models\AiProvider;
use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Services\ClaudeModelResolver;
use SuperAICore\Services\CliStatusDetector;
use SuperAICore\Services\EngineCatalog;
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

        // Engines come from the catalog, not a hardcoded list — so adding a
        // new CLI in EngineCatalog::seed() (or via config 'engines' override)
        // surfaces here automatically with its label, icon, and model list.
        $catalog = app(EngineCatalog::class);
        $engines = [];
        foreach ($catalog->all() as $key => $descriptor) {
            // SuperAgent is the only engine whose availability depends on a
            // composer dep being present at runtime; skip it when missing.
            if ($key === AiProvider::BACKEND_SUPERAGENT && !SuperAgentDetector::isAvailable()) {
                continue;
            }
            $engines[$key] = $descriptor;
        }
        $backends = array_keys($engines);

        $backendDisabled = [];
        foreach ($backends as $be) {
            $backendDisabled[$be] = self::isBackendDisabled($be);
        }

        $providersByBackend = [];
        foreach ($backends as $be) {
            $providersByBackend[$be] = $providers->where('backend', $be)->values();
        }

        $backendTypes = [];
        foreach ($backends as $be) {
            $backendTypes[$be] = $engines[$be]->providerTypes;
        }

        return view('super-ai-core::providers.index', compact(
            'providers', 'providersByBackend', 'cliStatuses', 'defaultBackend',
            'backends', 'backendTypes', 'backendDisabled', 'engines'
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
     * Test a built-in backend (local claude/codex/gemini CLI login) with a tiny prompt.
     */
    public function testBuiltin(Request $request, \SuperAICore\Services\Dispatcher $dispatcher)
    {
        $catalog = app(EngineCatalog::class);
        $cliEngines = array_filter(
            $catalog->all(),
            fn ($e) => $e->isCli && in_array(AiProvider::TYPE_BUILTIN, $e->providerTypes, true),
        );
        $request->validate(['backend' => 'required|in:' . implode(',', array_keys($cliEngines))]);

        // Pick the first dispatcher backend in the engine's chain — for CLI
        // engines that's always the *_cli entry, which is what builtin needs.
        $engine = $cliEngines[$request->input('backend')];
        $backendName = $engine->dispatcherBackends[0] ?? null;
        if (!$backendName) {
            return response()->json(['success' => false, 'message' => 'No dispatcher backend mapped for engine.']);
        }

        try {
            $result = $dispatcher->dispatch([
                'prompt' => 'Reply with exactly: OK',
                'max_tokens' => 10,
                'backend' => $backendName,
                'provider_config' => ['type' => 'builtin'],
                'task_type' => 'test_connection',
                'capability' => 'builtin_cli',
                'metadata' => ['origin' => 'provider.testBuiltin'],
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

        return response()->json(['models' => $this->fallbackModels($provider->type, $provider->backend)]);
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
                'task_type' => 'test_connection',
                'capability' => 'provider_ping',
                'metadata' => ['origin' => 'provider.test', 'provider_id' => $provider->id],
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
        $backends = [];
        foreach (app(EngineCatalog::class)->keys() as $key) {
            if ($key === AiProvider::BACKEND_SUPERAGENT && !SuperAgentDetector::isAvailable()) {
                continue;
            }
            $backends[] = $key;
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

    protected function fallbackModels(string $type, ?string $backend = null): array
    {
        // Vertex is ambiguous across engines — Claude + Gemini both use it.
        // Disambiguate on backend before falling back to type-based matching.
        if ($backend === AiProvider::BACKEND_GEMINI || $type === AiProvider::TYPE_GOOGLE_AI) {
            return array_map(
                fn ($m) => ['id' => $m['slug'], 'display_name' => $m['display_name']],
                \SuperAICore\Services\GeminiModelResolver::catalog(),
            );
        }
        if (str_starts_with($type, 'anthropic') || $type === AiProvider::TYPE_BEDROCK || $type === AiProvider::TYPE_VERTEX) {
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
        // Final fallback: ask the catalog whatever models the engine claims.
        if ($backend) {
            $models = app(EngineCatalog::class)->modelsFor($backend);
            return array_map(fn ($m) => ['id' => $m, 'display_name' => $m], $models);
        }
        return [];
    }
}
