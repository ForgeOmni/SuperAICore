<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SuperAICore\Models\AiSessionShare;
use SuperAICore\Services\ShareSessionService;

/**
 * Session-share endpoints (P3-10). The host wires this onto a UI button
 * (e.g. on `/processes` rows that have a session_id) to mint + revoke
 * shareable links.
 */
class ShareController extends Controller
{
    public function __construct(
        private readonly ShareSessionService $shares,
    ) {}

    public function create(string $sessionId): JsonResponse
    {
        if (!(bool) (config('super-ai-core.share.enabled') ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Sharing disabled by config.'], 403);
        }
        if ($sessionId === '') {
            return response()->json(['ok' => false, 'message' => 'session id is required.'], 422);
        }
        $row = $this->shares->create($sessionId);
        return response()->json([
            'ok'        => $row->status === AiSessionShare::STATUS_ACTIVE,
            'share_id'  => $row->share_id,
            'share_url' => $row->share_url,
            'status'    => $row->status,
            'message'   => $row->status === AiSessionShare::STATUS_ACTIVE
                            ? 'Share ready.'
                            : ($row->metadata['last_error'] ?? 'Share creation failed.'),
        ]);
    }

    public function show(string $sessionId): JsonResponse
    {
        $row = AiSessionShare::query()
            ->where('session_id', $sessionId)
            ->orderByDesc('created_at')
            ->first();
        if ($row === null) {
            return response()->json(['ok' => false, 'message' => 'No share for this session.'], 404);
        }
        return response()->json([
            'ok'        => $row->status === AiSessionShare::STATUS_ACTIVE,
            'share_id'  => $row->share_id,
            'share_url' => $row->share_url,
            'status'    => $row->status,
            'synced_at' => $row->synced_at?->toIso8601String(),
        ]);
    }

    public function destroy(string $sessionId): JsonResponse
    {
        $row = AiSessionShare::query()
            ->where('session_id', $sessionId)
            ->where('status', AiSessionShare::STATUS_ACTIVE)
            ->orderByDesc('created_at')
            ->first();
        if ($row === null) {
            return response()->json(['ok' => false, 'message' => 'No active share for this session.'], 404);
        }
        $this->shares->destroy($row);
        return response()->json(['ok' => true, 'message' => 'Share revoked.']);
    }
}
