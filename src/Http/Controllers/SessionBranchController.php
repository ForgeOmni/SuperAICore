<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SuperAICore\Services\SessionBranchManager;

/**
 * HTTP surface for the Pi-style session tree.
 *
 *   GET    /super-ai-core/sessions/{sessionId}/tree           – full branch tree
 *   POST   /super-ai-core/sessions/{sessionId}/fork           – fork at an entry
 *   POST   /super-ai-core/sessions/{sessionId}/switch         – switch active branch
 */
final class SessionBranchController extends Controller
{
    public function __construct(private SessionBranchManager $branches) {}

    public function tree(string $sessionId): JsonResponse
    {
        return response()->json([
            'session_id' => $sessionId,
            'tree'       => $this->branches->getTree($sessionId),
        ]);
    }

    public function fork(Request $request, string $sessionId): JsonResponse
    {
        $request->validate([
            'from_entry_id'    => ['required', 'string', 'max:16'],
            'parent_branch_id' => ['nullable', 'string', 'max:16'],
            'display_name'     => ['nullable', 'string', 'max:120'],
        ]);

        $branch = $this->branches->createFork(
            $sessionId,
            (string) $request->input('from_entry_id'),
            $request->input('parent_branch_id'),
            $request->input('display_name'),
        );

        return response()->json([
            'branch_id' => $branch->branch_id,
        ], 201);
    }

    public function switchActive(Request $request, string $sessionId): JsonResponse
    {
        $request->validate([
            'branch_id'         => ['required', 'string', 'max:16'],
            'abandoned_summary' => ['nullable', 'string'],
        ]);

        $this->branches->switchTo(
            $sessionId,
            (string) $request->input('branch_id'),
            $request->input('abandoned_summary'),
        );

        return response()->json(['ok' => true]);
    }
}
