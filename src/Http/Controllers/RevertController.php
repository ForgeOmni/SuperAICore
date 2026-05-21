<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SuperAICore\Models\AiUsageLog;
use SuperAICore\Services\SnapshotDiffService;

/**
 * Revert the project worktree to the snapshot captured BEFORE a given
 * UsageLog row's dispatch ran. Opencode-inspired (`session/revert.ts`)
 * but scoped to a single dispatch instead of a message+part graph —
 * SuperAICore's per-call audit log is the natural granularity.
 *
 * Semantics (matching SuperAgent SDK's `GitShadowStore::restore()`):
 *   - Tracked files revert to the snapshot state.
 *   - Untracked files added since the snapshot are LEFT in place.
 *
 * Gated by `super-ai-core.snapshot.revert_enabled` (default true). The
 * route still exists when disabled — returns 403 — so a bookmarked
 * link doesn't 404 out of the blue.
 */
class RevertController extends Controller
{
    public function __construct(
        private readonly SnapshotDiffService $snapshotDiff,
    ) {}

    public function revert(int $id): JsonResponse
    {
        if (!(bool) (config('super-ai-core.snapshot.revert_enabled') ?? true)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Revert is disabled by configuration (super-ai-core.snapshot.revert_enabled).',
            ], 403);
        }

        $row = AiUsageLog::find($id);
        if ($row === null) {
            return response()->json(['ok' => false, 'message' => 'Usage log not found.'], 404);
        }
        $snapshot = (string) ($row->pre_snapshot ?? '');
        if ($snapshot === '') {
            return response()->json([
                'ok'      => false,
                'message' => 'This dispatch did not record a pre_snapshot — nothing to revert to.',
            ], 422);
        }

        $projectRoot = $this->resolveProjectRoot();
        if ($projectRoot === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Could not resolve project root for revert.',
            ], 500);
        }

        $ok = $this->snapshotDiff->restore($projectRoot, $snapshot);
        if (!$ok) {
            return response()->json([
                'ok'      => false,
                'message' => "Revert failed — snapshot {$snapshot} may have been pruned.",
            ], 500);
        }

        return response()->json([
            'ok'       => true,
            'message'  => "Worktree restored to snapshot " . substr($snapshot, 0, 7) . '.',
            'snapshot' => $snapshot,
        ]);
    }

    private function resolveProjectRoot(): ?string
    {
        $candidates = [
            (string) (config('super-ai-core.snapshot.project_root') ?? ''),
        ];
        if (function_exists('base_path')) {
            try { $candidates[] = base_path(); } catch (\Throwable) {}
        }
        $candidates[] = getcwd() ?: '';
        foreach ($candidates as $cand) {
            if (!is_string($cand) || $cand === '') continue;
            if (!is_dir($cand)) continue;
            $resolved = realpath($cand);
            if ($resolved !== false) return $resolved;
        }
        return null;
    }
}
