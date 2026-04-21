<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Models\AiCapability;
use SuperAICore\Models\AiService;
use SuperAICore\Models\AiServiceRouting;
use SuperAICore\Services\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * AI Service + Capability + Routing CRUD.
 *
 * Authorization is the host app's responsibility — attach middleware
 * via config('super-ai-core.route.middleware') or override routes.
 */
class AiServiceController extends Controller
{
    public function index()
    {
        $capabilities = AiCapability::orderBy('sort_order')->orderBy('id')->get();
        $services = AiService::with('capability')->orderBy('sort_order')->orderBy('id')->get();

        return view('super-ai-core::integrations.ai-services', compact('capabilities', 'services'));
    }

    public function routingIndex()
    {
        $capabilities = AiCapability::where('is_active', true)->orderBy('sort_order')->get();
        $services = AiService::where('is_active', true)->orderBy('sort_order')->get();
        $routings = AiServiceRouting::with(['capability', 'service'])
            ->orderBy('task_type')->orderBy('capability_id')->orderByDesc('priority')->get();

        // Task types — host provides via config or uses empty array
        $taskTypes = config('super-ai-core.task_types', []);

        return view('super-ai-core::integrations.ai-service-routing', compact('capabilities', 'services', 'routings', 'taskTypes'));
    }

    // ─── Capabilities CRUD ───

    public function storeCapability(Request $request)
    {
        $request->validate([
            'slug' => 'required|string|max:50|unique:ai_capabilities,slug',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);

        $data = $request->only('slug', 'name', 'description');
        if ($request->filled('pre_process')) {
            $data['pre_process'] = json_decode($request->input('pre_process'), true);
        }
        AiCapability::create($data);
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    public function updateCapability(Request $request, AiCapability $capability)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);

        $data = $request->only('name', 'description');
        if ($request->has('pre_process')) {
            $data['pre_process'] = $request->filled('pre_process')
                ? json_decode($request->input('pre_process'), true)
                : null;
        }
        $capability->update($data);
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    public function destroyCapability(AiCapability $capability)
    {
        $capability->delete();
        return back()->with('success', __('super-ai-core::messages.deleted'));
    }

    public function toggleCapability(AiCapability $capability)
    {
        $capability->update(['is_active' => !$capability->is_active]);
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    // ─── Services CRUD ───

    public function storeService(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'capability_id' => 'required|exists:ai_capabilities,id',
            'protocol' => 'required|in:anthropic,openai,minimax,superagent',
            'base_url' => 'required|string|max:500',
            'api_key' => 'nullable|string|max:500',
            'model' => 'required|string|max:100',
        ]);

        AiService::create($request->only(
            'name', 'capability_id', 'protocol', 'base_url', 'api_key', 'model'
        ));
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    public function updateService(Request $request, AiService $service)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'protocol' => 'required|in:anthropic,openai,minimax,superagent',
            'base_url' => 'required|string|max:500',
            'api_key' => 'nullable|string|max:500',
            'model' => 'required|string|max:100',
        ]);

        $data = $request->only('name', 'protocol', 'base_url', 'model');
        if ($request->filled('api_key')) {
            $data['api_key'] = $request->input('api_key');
        }
        $service->update($data);
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    public function destroyService(AiService $service)
    {
        $service->delete();
        return back()->with('success', __('super-ai-core::messages.deleted'));
    }

    public function toggleService(AiService $service)
    {
        $service->update(['is_active' => !$service->is_active]);
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    /**
     * Test service connection via the Dispatcher with a tiny prompt.
     */
    public function testService(AiService $service, Dispatcher $dispatcher)
    {
        try {
            $result = $dispatcher->dispatch([
                'prompt' => 'Reply with exactly: OK',
                'max_tokens' => 10,
                'backend' => $this->backendForProtocol($service->protocol),
                'model' => $service->model,
                'provider_config' => [
                    'api_key' => $service->decrypted_api_key,
                    'base_url' => $service->base_url,
                ],
                'task_type' => 'test_connection',
                'capability' => 'service_ping',
                'metadata' => ['origin' => 'ai_service.test', 'service_id' => $service->id],
            ]);

            if ($result && !empty($result['text'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connected. Response: ' . mb_substr($result['text'], 0, 100),
                ]);
            }
            return response()->json(['success' => false, 'message' => 'No response received']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function backendForProtocol(string $protocol): string
    {
        return match ($protocol) {
            'anthropic' => 'anthropic_api',
            'openai', 'minimax' => 'openai_api',
            'superagent' => 'superagent',
            default => 'anthropic_api',
        };
    }

    // ─── Routing CRUD ───

    public function storeRouting(Request $request)
    {
        $request->validate([
            'task_type' => 'required|string|max:60',
            'capability_id' => 'required|exists:ai_capabilities,id',
            'service_id' => 'required|exists:ai_services,id',
            'priority' => 'nullable|integer',
        ]);

        AiServiceRouting::create([
            'task_type' => $request->input('task_type'),
            'capability_id' => $request->input('capability_id'),
            'service_id' => $request->input('service_id'),
            'priority' => $request->input('priority', 0),
        ]);
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    public function updateRouting(Request $request, AiServiceRouting $routing)
    {
        $request->validate([
            'task_type' => 'required|string|max:60',
            'service_id' => 'required|exists:ai_services,id',
            'priority' => 'nullable|integer',
        ]);

        $routing->update($request->only('task_type', 'service_id', 'priority'));
        return back()->with('success', __('super-ai-core::messages.saved'));
    }

    public function destroyRouting(AiServiceRouting $routing)
    {
        $routing->delete();
        return back()->with('success', __('super-ai-core::messages.deleted'));
    }

    public function toggleRouting(AiServiceRouting $routing)
    {
        $routing->update(['is_active' => !$routing->is_active]);
        return back()->with('success', __('super-ai-core::messages.saved'));
    }
}
