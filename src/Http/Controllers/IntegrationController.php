<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Services\McpManager;
use SuperAICore\Services\SystemToolManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * MCP Server + 3rd-party integration management.
 * Delegates to McpManager and SystemToolManager (all static methods).
 */
class IntegrationController extends Controller
{
    public function index()
    {
        $registry = McpManager::getRegistry();
        $statuses = McpManager::getAllStatuses();
        $categories = McpManager::getCategories();

        // Merge registry metadata into status rows so the view has a single "servers" list
        // shaped identically to SuperTeam's original.
        $servers = [];
        foreach ($statuses as $key => $status) {
            $def = $registry[$key] ?? [];
            $servers[$key] = array_merge([
                'key' => $key,
                'name' => $def['name'] ?? $key,
                'icon' => $def['icon'] ?? 'bi-box',
                'color' => $def['color'] ?? '#6b7280',
                'category' => $def['category'] ?? 'other',
                'type' => $def['type'] ?? 'custom',
                'capabilities' => $def['capabilities'] ?? [],
                'requires_auth' => (bool) ($def['requires_auth'] ?? false),
                'config_fields' => $def['config_fields'] ?? [],
                'in_registry' => array_key_exists($key, $registry),
                'dependency_ready' => (bool) ($status['dependency_ready'] ?? true),
                'installed' => (bool) ($status['installed'] ?? false),
                'auth_status' => $status['auth_status'] ?? null,
            ], $status);
        }

        $systemTools = SystemToolManager::getAllTools();

        return view('super-ai-core::integrations.index', compact(
            'registry', 'statuses', 'categories', 'servers', 'systemTools'
        ));
    }

    public function install(Request $request, string $key)
    {
        $configValues = (array) $request->input('config', []);
        $result = McpManager::install($key, $configValues);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        if ($result['success']) {
            return back()->with('success', $this->serverName($key) . ' installed.');
        }
        return back()->with('error', $this->translateError($result['message'] ?? 'unknown', $key));
    }

    public function uninstall(Request $request, string $key)
    {
        $deleteFiles = (bool) $request->input('delete_files', false);
        $result = McpManager::uninstall($key, $deleteFiles);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return $result['success']
            ? back()->with('success', $this->serverName($key) . ' uninstalled.')
            : back()->with('error', __('super-ai-core::integrations.uninstall_failed', ['name' => $this->serverName($key)]));
    }

    public function startAuth(Request $request, string $key)
    {
        $result = McpManager::startAuth($key);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return $result['success']
            ? back()->with('success', __('super-ai-core::integrations.auth_started'))
            : back()->with('error', $this->translateError($result['message'] ?? 'auth_failed', $key));
    }

    public function clearAuth(Request $request, string $key)
    {
        $result = McpManager::clearAuth($key);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return $result['success']
            ? back()->with('success', __('super-ai-core::integrations.auth_cleared', ['name' => $this->serverName($key)]))
            : back()->with('error', __('super-ai-core::integrations.auth_clear_failed', ['name' => $this->serverName($key)]));
    }

    public function test(string $key)
    {
        return response()->json(McpManager::testConnection($key));
    }

    public function status()
    {
        return response()->json(McpManager::getAllStatuses());
    }

    // ── Batch ──

    public function batchCheck()
    {
        $statuses = McpManager::getAllStatuses();
        $results = [];
        foreach ($statuses as $key => $server) {
            if (!$server['installed']) continue;
            $test = McpManager::testConnection($key);
            $results[] = [
                'key' => $key,
                'name' => $server['name'] ?? $key,
                'ready' => $test['success'] ?? false,
                'message' => $test['message'] ?? '',
                'dependency_ready' => $server['dependency_ready'] ?? true,
            ];
        }
        return response()->json(['results' => $results]);
    }

    public function batchInstall(Request $request)
    {
        set_time_limit(0);
        McpManager::ensureUvInstalled();

        $statuses = McpManager::getAllStatuses();
        $results = [];
        $isWindows = PHP_OS_FAMILY === 'Windows';

        // Phase 1: Repair broken installs
        foreach ($statuses as $key => $server) {
            if (!($server['installed'] ?? false) || !($server['in_registry'] ?? true)) continue;
            if (!empty($server['config_fields'])) continue;

            $test = McpManager::testConnection($key);
            if ($test['success'] ?? false) continue;

            McpManager::uninstall($key);
            $result = McpManager::install($key);
            $results[] = [
                'key' => $key,
                'name' => $server['name'] ?? $key,
                'success' => $result['success'],
                'message' => $result['success']
                    ? __('super-ai-core::integrations.batch_repaired')
                    : $this->translateError($result['message'] ?? 'unknown', $key),
            ];
            if ($isWindows) usleep(500000);
        }

        // Phase 2: Install any remaining available servers without required config
        $statuses = McpManager::getAllStatuses();
        foreach ($statuses as $key => $server) {
            if ($server['installed'] ?? false) continue;
            if (!($server['in_registry'] ?? true)) continue;
            if (!empty($server['config_fields'])) continue;

            $result = McpManager::install($key);
            $results[] = [
                'key' => $key,
                'name' => $server['name'] ?? $key,
                'success' => $result['success'],
                'message' => $result['success'] ? 'installed' : $this->translateError($result['message'] ?? 'unknown', $key),
            ];
            if ($isWindows) usleep(500000);
        }

        return response()->json(['results' => $results]);
    }

    // ── System Tools ──

    public function systemToolCommands(string $toolKey)
    {
        return response()->json(SystemToolManager::getInstallCommands($toolKey));
    }

    public function installSystemTool(Request $request, string $toolKey)
    {
        $result = SystemToolManager::install($toolKey);
        if ($request->expectsJson()) {
            return response()->json($result);
        }
        return $result['success']
            ? back()->with('success', __('super-ai-core::integrations.system_tool_installed', ['name' => $toolKey]))
            : back()->with('error', __('super-ai-core::integrations.system_tool_install_failed', ['name' => $toolKey, 'error' => $result['message']]));
    }

    public function installTesseractLanguage(Request $request, string $langCode)
    {
        $result = SystemToolManager::installTesseractLanguage($langCode);
        if ($request->expectsJson()) {
            return response()->json($result);
        }
        return $result['success']
            ? back()->with('success', __('super-ai-core::integrations.tesseract_language_installed', ['lang' => $langCode]))
            : back()->with('error', __('super-ai-core::integrations.tesseract_language_install_failed', ['lang' => $langCode]));
    }

    public function systemToolsStatus()
    {
        return response()->json(SystemToolManager::getAllTools());
    }

    // ── Helpers ──

    protected function serverName(string $key): string
    {
        $info = McpManager::getRegistry()[$key] ?? null;
        return $info['name'] ?? ucfirst($key);
    }

    protected function translateError(string $errorKey, string $serverKey): string
    {
        $map = [
            'uvx_not_found' => 'err_uvx_not_found',
            'npx_not_found' => 'err_npx_not_found',
            'git_not_found' => 'err_git_not_found',
            'python_not_found' => 'err_python_not_found',
            'clone_failed' => 'err_clone_failed',
            'pip_install_failed' => 'err_pip_failed',
            'command_not_found' => 'err_command_not_found',
            'go_not_found' => 'err_go_not_found',
            'build_failed' => 'err_build_failed',
            'curl_not_found' => 'err_curl_not_found',
            'download_failed' => 'err_download_failed',
            'binary_not_found' => 'err_binary_not_found',
            'unsupported_platform' => 'err_unsupported_platform',
            'auth_failed' => 'err_auth_failed',
        ];
        if (isset($map[$errorKey])) {
            return __('super-ai-core::integrations.' . $map[$errorKey]);
        }
        return __('super-ai-core::integrations.err_unknown', ['error' => $errorKey]);
    }
}
