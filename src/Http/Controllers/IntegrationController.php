<?php

namespace ForgeOmni\AiCore\Http\Controllers;

use ForgeOmni\AiCore\Services\McpManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * MCP Server + 3rd-party integration management.
 *
 * PLACEHOLDER — full McpManager port (2953 lines) and UI pending.
 * Currently exposes a minimal "show registry + integration configs" view.
 */
class IntegrationController extends Controller
{
    public function __construct(protected McpManager $mcp) {}

    public function index()
    {
        $registry = $this->mcp->registry();
        return view('ai-core::integrations.index', compact('registry'));
    }

    public function install(Request $request, string $key)
    {
        return response()->json($this->mcp->install($key));
    }

    public function uninstall(Request $request, string $key)
    {
        return response()->json($this->mcp->uninstall($key));
    }

    public function test(string $key)
    {
        return response()->json($this->mcp->test($key));
    }

    public function status()
    {
        return response()->json([
            'servers' => array_keys($this->mcp->registry()),
        ]);
    }
}
