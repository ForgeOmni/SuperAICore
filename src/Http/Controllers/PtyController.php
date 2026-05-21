<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SuperAICore\Models\AiPtySession;
use SuperAICore\Services\PtyService;

/**
 * Long-poll PTY endpoints (P3-9 Phase 1).
 *
 *   POST /pty/sessions               body: {command, cwd?, title?}
 *   GET  /pty/sessions/{id}                    → row state
 *   POST /pty/sessions/{id}/write    body: {data}    Phase 1: not implemented
 *                                                    (stdin pipe is detached)
 *   GET  /pty/sessions/{id}/poll?cursor=N       → {chunk, cursor, status, exit_code}
 *   POST /pty/sessions/{id}/kill                → kills the process
 */
class PtyController extends Controller
{
    public function __construct(
        private readonly PtyService $service,
    ) {}

    public function create(Request $request): JsonResponse
    {
        if (!(bool) (config('super-ai-core.pty.enabled') ?? false)) {
            return response()->json(['ok' => false, 'message' => 'PTY sessions disabled by config.'], 403);
        }
        $command = (string) $request->input('command', '');
        if ($command === '') {
            return response()->json(['ok' => false, 'message' => '`command` is required.'], 422);
        }
        $cwd   = $request->input('cwd');
        $title = $request->input('title');
        $row = $this->service->spawn($command, is_string($cwd) ? $cwd : null, is_string($title) ? $title : null);
        return response()->json(['ok' => true, 'session' => $row->toArray()]);
    }

    public function show(int $id): JsonResponse
    {
        $row = AiPtySession::find($id);
        if ($row === null) return response()->json(['ok' => false, 'message' => 'Not found.'], 404);
        return response()->json(['ok' => true, 'session' => $row->toArray()]);
    }

    public function write(Request $request, int $id): JsonResponse
    {
        // Phase 1: stdin write is not supported because PHP can't keep a
        // pipe alive across HTTP requests without a persistent worker.
        // Caller can spawn an `expect`-style command from the client side
        // if input is required.
        return response()->json([
            'ok'      => false,
            'message' => 'PTY write is not implemented in Phase 1 — stdin pipe is detached.',
        ], 501);
    }

    public function poll(Request $request, int $id): JsonResponse
    {
        $row = AiPtySession::find($id);
        if ($row === null) return response()->json(['ok' => false, 'message' => 'Not found.'], 404);
        $cursor = (int) $request->query('cursor', 0);
        return response()->json(array_merge(['ok' => true, 'id' => $row->id], $this->service->poll($row, $cursor)));
    }

    public function kill(int $id): JsonResponse
    {
        $row = AiPtySession::find($id);
        if ($row === null) return response()->json(['ok' => false, 'message' => 'Not found.'], 404);
        $ok = $this->service->kill($row);
        return response()->json(['ok' => $ok, 'message' => $ok ? 'Terminated.' : 'Could not terminate (no posix_kill?).']);
    }
}
