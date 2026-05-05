<?php

declare(strict_types=1);

namespace SuperAICore\Goals;

use Ramsey\Uuid\Uuid;
use SuperAgent\Goals\Contracts\GoalStore;
use SuperAgent\Goals\Goal;
use SuperAgent\Goals\GoalAlreadyExistsException;
use SuperAgent\Goals\GoalStatus;
use SuperAICore\Models\AiGoal;

/**
 * Persistent backing for the SuperAgent SDK's `GoalStore` SPI.
 *
 * Each thread can have at most one row in non-terminal status
 * (active / paused / budget_limited) at a time — `create()` enforces
 * this by querying first and refusing when one is already there. The
 * uniqueness is NOT enforced at the DB level (a partial unique index
 * isn't portable across MySQL/SQLite/Postgres in the way Laravel
 * migrations expose), so the application-level guard is authoritative.
 *
 * Thread-resume semantics: paused goals stay paused after the host
 * process restarts (codex behaviour, ported in v0.10) — the store
 * simply returns whatever status was persisted.
 */
final class EloquentGoalStore implements GoalStore
{
    public function create(string $threadId, string $objective, ?int $tokenBudget): Goal
    {
        $existing = AiGoal::query()
            ->where('thread_id', $threadId)
            ->whereIn('status', [
                AiGoal::STATUS_ACTIVE,
                AiGoal::STATUS_PAUSED,
                AiGoal::STATUS_BUDGET_LIMITED,
            ])
            ->first();
        if ($existing) {
            throw new GoalAlreadyExistsException(
                "Thread {$threadId} already has goal {$existing->id} "
                . "(status: {$existing->status}). Call update_goal to mark "
                . "it complete before creating a new one."
            );
        }
        $row = AiGoal::create([
            'id'           => Uuid::uuid4()->toString(),
            'thread_id'    => $threadId,
            'objective'    => $objective,
            'status'       => AiGoal::STATUS_ACTIVE,
            'token_budget' => $tokenBudget,
            'tokens_used'  => 0,
        ]);
        return $this->toGoal($row);
    }

    public function findActive(string $threadId): ?Goal
    {
        $row = AiGoal::query()
            ->where('thread_id', $threadId)
            ->whereIn('status', [
                AiGoal::STATUS_ACTIVE,
                AiGoal::STATUS_PAUSED,
                AiGoal::STATUS_BUDGET_LIMITED,
            ])
            ->orderByDesc('created_at')
            ->first();
        return $row ? $this->toGoal($row) : null;
    }

    public function findById(string $id): ?Goal
    {
        $row = AiGoal::find($id);
        return $row ? $this->toGoal($row) : null;
    }

    public function transition(string $id, GoalStatus $status): ?Goal
    {
        $row = AiGoal::find($id);
        if (! $row) return null;
        $row->status = $status->value;
        $row->save();
        return $this->toGoal($row);
    }

    public function recordTokens(string $id, int $tokensUsed): ?Goal
    {
        $row = AiGoal::find($id);
        if (! $row) return null;
        $row->tokens_used = max(0, $tokensUsed);
        $row->save();
        return $this->toGoal($row);
    }

    private function toGoal(AiGoal $row): Goal
    {
        return new Goal(
            id:          (string) $row->id,
            threadId:    (string) $row->thread_id,
            objective:   (string) $row->objective,
            status:      GoalStatus::from((string) $row->status),
            tokenBudget: $row->token_budget !== null ? (int) $row->token_budget : null,
            tokensUsed:  (int) $row->tokens_used,
            createdAt:   (int) ($row->created_at ? $row->created_at->timestamp : time()),
            updatedAt:   (int) ($row->updated_at ? $row->updated_at->timestamp : time()),
        );
    }
}
