<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use Illuminate\Support\Facades\DB;
use SuperAICore\Models\AiSessionBranch;

/**
 * Pi-style branch manager.
 *
 * Pi's /tree command forks a session at an arbitrary previous entry. When
 * the user switches to a different branch, the abandoned branch is
 * auto-summarized and stored so its context isn't lost.
 *
 * This service is the relational equivalent for SuperAICore. It manages
 * the `ai_session_branches` table and provides:
 *
 *   - getTree(sessionId) : full branch tree for the UI
 *   - createFork(sessionId, fromEntryId, displayName?) : new branch
 *   - switchTo(sessionId, branchId, summary?) : flip active branch and
 *     archive a summary of the previously-active one
 *   - abandonedSummary(sessionId, branchId) : the text the LLM was given
 *     when the user navigated away (mirrors pi BranchSummaryEntry)
 */
final class SessionBranchManager
{
    /**
     * @return array<int, array{
     *   branch_id: string,
     *   parent_branch_id: ?string,
     *   fork_from_entry_id: ?string,
     *   summary: ?string,
     *   is_active: bool,
     *   display_name: ?string,
     * }>
     */
    public function getTree(string $sessionId): array
    {
        return AiSessionBranch::query()
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->get()
            ->map(fn(AiSessionBranch $b) => [
                'branch_id'          => $b->branch_id,
                'parent_branch_id'   => $b->parent_branch_id,
                'fork_from_entry_id' => $b->fork_from_entry_id,
                'summary'            => $b->summary,
                'is_active'          => $b->is_active,
                'display_name'       => $b->display_name,
            ])
            ->all();
    }

    public function ensureTrunk(string $sessionId): AiSessionBranch
    {
        $trunk = AiSessionBranch::query()
            ->where('session_id', $sessionId)
            ->whereNull('parent_branch_id')
            ->first();
        if ($trunk !== null) return $trunk;

        return AiSessionBranch::create([
            'session_id'       => $sessionId,
            'branch_id'        => $this->shortId(),
            'parent_branch_id' => null,
            'is_active'        => true,
            'display_name'     => 'trunk',
        ]);
    }

    public function createFork(
        string $sessionId,
        string $fromEntryId,
        ?string $parentBranchId = null,
        ?string $displayName = null,
    ): AiSessionBranch {
        $this->ensureTrunk($sessionId);

        return AiSessionBranch::create([
            'session_id'         => $sessionId,
            'branch_id'          => $this->shortId(),
            'parent_branch_id'   => $parentBranchId,
            'fork_from_entry_id' => $fromEntryId,
            'is_active'          => false,
            'display_name'       => $displayName,
        ]);
    }

    public function switchTo(string $sessionId, string $targetBranchId, ?string $abandonedSummary = null): void
    {
        DB::transaction(function () use ($sessionId, $targetBranchId, $abandonedSummary) {
            $previous = AiSessionBranch::query()
                ->where('session_id', $sessionId)
                ->where('is_active', true)
                ->first();

            if ($previous !== null && $previous->branch_id !== $targetBranchId) {
                $previous->is_active = false;
                if ($abandonedSummary !== null) {
                    $previous->summary = $abandonedSummary;
                    $previous->summary_details = array_merge(
                        $previous->summary_details ?? [],
                        ['archived_at' => now()->toIso8601String()],
                    );
                }
                $previous->save();
            }

            AiSessionBranch::query()
                ->where('session_id', $sessionId)
                ->where('branch_id', $targetBranchId)
                ->update(['is_active' => true]);
        });
    }

    public function abandonedSummary(string $sessionId, string $branchId): ?string
    {
        $row = AiSessionBranch::query()
            ->where('session_id', $sessionId)
            ->where('branch_id', $branchId)
            ->first();
        return $row?->summary;
    }

    private function shortId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
