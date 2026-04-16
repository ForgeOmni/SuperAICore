<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Models\AiModelSetting;
use SuperAICore\Models\AiProvider;
use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Services\CliStatusDetector;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Per-task-type model/effort settings.
 *
 * The task types themselves are host-specific and must be registered
 * via config('super-ai-core.task_types'):
 *
 *   'task_types' => [
 *       'group_key' => [
 *           'label' => 'Group label',
 *           'types' => [
 *               'task_type_key' => ['label' => 'Task type label'],
 *           ],
 *       ],
 *   ]
 *
 * SuperTeam populates this from App\Models\Task::typeGroups() in its
 * AppServiceProvider::boot().
 */
class TaskModelController extends Controller
{
    public function index(Request $request)
    {
        $taskGroups = config('super-ai-core.task_types', []);
        if (empty($taskGroups)) {
            return response('No task types registered. Set config("super-ai-core.task_types") in your host app.', 422);
        }

        $providers = AiProvider::where('scope', 'global')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $selectedProviderId = $request->get('provider_id') ? (int) $request->get('provider_id') : null;
        $selectedProvider = $selectedProviderId ? $providers->firstWhere('id', $selectedProviderId) : null;
        $selectedBackend = $selectedProvider
            ? $selectedProvider->backend
            : ($request->get('backend') ?: 'claude');

        $settings = AiModelSetting::getForScope('global', null, $selectedProviderId, $selectedBackend);
        $defaultBackend = IntegrationConfig::getValue('ai_execution', 'default_backend') ?: 'claude';

        $providersByBackend = [];
        foreach (AiProvider::BACKENDS as $be) {
            $providersByBackend[$be] = $providers->where('backend', $be)->values();
        }

        return view('super-ai-core::task-models.index', compact(
            'taskGroups',
            'settings',
            'providers',
            'providersByBackend',
            'selectedProviderId',
            'selectedBackend',
            'defaultBackend',
        ));
    }

    public function save(Request $request)
    {
        $providerId = $request->input('provider_id') ? (int) $request->input('provider_id') : null;
        $backend = $request->input('backend', 'claude');

        $parsed = [];
        foreach ($request->except(['_token', 'provider_id', 'backend']) as $field => $value) {
            if ($field === 'default') {
                $parsed['default']['model'] = $value;
                continue;
            }
            if (str_ends_with($field, '_effort')) {
                $taskType = substr($field, 0, -7);
                if ($taskType === 'default') continue;
                $parsed[$taskType]['effort'] = $value;
            } elseif (str_ends_with($field, '_model')) {
                $taskType = substr($field, 0, -6);
                $parsed[$taskType]['model'] = $value;
            } else {
                $parsed[$field]['model'] = $value;
            }
        }

        AiModelSetting::saveForScope($parsed, 'global', null, $providerId, $backend);

        return redirect()->route('super-ai-core.task-models.index', array_filter([
            'backend' => $backend,
            'provider_id' => $providerId,
        ]))->with('success', __('super-ai-core::messages.task_models_saved'));
    }
}
