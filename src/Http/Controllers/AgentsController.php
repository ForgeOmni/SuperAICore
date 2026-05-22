<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SuperAICore\Services\AgentCatalog;

/**
 * SuperTeam agent browser.
 *
 *   GET  /super-ai-core/agents          – grouped agent list (HTML)
 *   GET  /super-ai-core/agents.json     – grouped agent list (JSON)
 *   GET  /super-ai-core/agents/{name}   – single agent detail
 */
final class AgentsController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $catalog = AgentCatalog::fromConfig();
        $grouped = $catalog->groupedByCategory();

        if ($request->wantsJson() || $request->is('*.json')) {
            return response()->json($grouped);
        }

        $total = array_sum(array_map('count', $grouped));
        return view('super-ai-core::agents.index', [
            'grouped' => $grouped,
            'total'   => $total,
        ]);
    }

    public function show(string $name)
    {
        $catalog = AgentCatalog::fromConfig();
        $agent = $catalog->find($name);
        if ($agent === null) {
            abort(404, 'Agent not found: ' . $name);
        }
        $body = $catalog->body($agent['file']);

        return view('super-ai-core::agents.show', [
            'agent' => $agent,
            'body'  => $body,
        ]);
    }
}
