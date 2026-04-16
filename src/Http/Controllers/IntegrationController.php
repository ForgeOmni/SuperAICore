<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Services\McpManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * MCP Server + 3rd-party integration management.
 * Delegates to McpManager (all static methods).
 */
class IntegrationController extends Controller
{
    public function index()
    {
        $registry = McpManager::getRegistry();
        $statuses = McpManager::getAllStatuses();
        $categories = McpManager::getCategories();
        return view('super-ai-core::integrations.index', compact('registry', 'statuses', 'categories'));
    }

    public function install(Request $request, string $key)
    {
        $configValues = $request->input('config', []);
        return response()->json(McpManager::install($key, $configValues));
    }

    public function uninstall(Request $request, string $key)
    {
        $deleteFiles = (bool) $request->input('delete_files', false);
        return response()->json(McpManager::uninstall($key, $deleteFiles));
    }

    public function startAuth(string $key)
    {
        return response()->json(McpManager::startAuth($key));
    }

    public function clearAuth(string $key)
    {
        return response()->json(McpManager::clearAuth($key));
    }

    public function test(string $key)
    {
        return response()->json(McpManager::testConnection($key));
    }

    public function status()
    {
        return response()->json(McpManager::getAllStatuses());
    }
}
