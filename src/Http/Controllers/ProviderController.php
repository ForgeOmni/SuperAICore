<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Models\AiProvider;
use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Services\CliStatusDetector;
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

        return view('super-ai-core::providers.index', compact('providers', 'cliStatuses', 'defaultBackend'));
    }

    public function saveDefaultBackend(Request $request)
    {
        $request->validate(['backend' => 'required|in:claude,codex,superagent']);
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

    protected function validated(Request $request, ?AiProvider $provider = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'backend' => 'nullable|in:' . implode(',', AiProvider::BACKENDS),
            'type' => ($provider ? 'nullable' : 'required') . '|in:' . implode(',', array_keys(AiProvider::TYPES)),
            'api_key' => 'nullable|string|max:500',
            'base_url' => 'nullable|url|max:500',
            'extra_config' => 'nullable|array',
            'extra_config.region' => 'nullable|string|max:100',
            'extra_config.access_key_id' => 'nullable|string|max:255',
            'extra_config.secret_access_key' => 'nullable|string|max:255',
            'extra_config.project_id' => 'nullable|string|max:255',
        ]);
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
            return [
                ['id' => 'claude-opus-4-6', 'display_name' => 'Opus 4.6'],
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Sonnet 4.6'],
                ['id' => 'claude-sonnet-4-5-20241022', 'display_name' => 'Sonnet 4.5'],
                ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Haiku 4.5'],
            ];
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
