<?php

namespace SuperAICore\Services;

use Carbon\Carbon;
use SuperAICore\Models\SkillExecution;
use Illuminate\Support\Facades\DB;

/**
 * Telemetry for Claude Code Skill tool invocations.
 *
 * Borrowed in spirit from OpenSpace's `skill_engine/store.py` — but trimmed:
 *   - one table only (no version DAG, no judgment / lineage tables)
 *   - no embedding cache
 *
 * Hook lifecycle:
 *   PreToolUse(Skill)  →  start(skill, session, …)        returns id
 *   Stop               →  closeSession(session, status)   closes any open rows
 *
 * Metrics (per skill):
 *   applied_count, completed_count, failed_count, orphaned_count
 *   completion_rate = completed / applied
 *   failure_rate    = failed    / applied
 *   p50_duration_ms, p95_duration_ms (over completed)
 */
class SkillTelemetry
{
    /** Insert a new "in_progress" row. Returns the row id. */
    public static function start(
        string $skillName,
        ?string $sessionId = null,
        ?string $hostApp = null,
        ?string $transcriptPath = null,
        ?string $cwd = null,
        ?array $metadata = null,
    ): int {
        $row = SkillExecution::create([
            'skill_name'      => self::normaliseName($skillName),
            'host_app'        => $hostApp,
            'session_id'      => $sessionId,
            'status'          => SkillExecution::STATUS_IN_PROGRESS,
            'started_at'      => Carbon::now(),
            'transcript_path' => $transcriptPath,
            'cwd'             => $cwd,
            'metadata'        => $metadata,
        ]);
        return (int) $row->id;
    }

    /** Mark a single execution row complete. */
    public static function stop(
        int $executionId,
        string $status = SkillExecution::STATUS_COMPLETED,
        ?string $errorSummary = null,
    ): bool {
        $row = SkillExecution::find($executionId);
        if (!$row) return false;
        if ($row->status !== SkillExecution::STATUS_IN_PROGRESS) {
            return false;
        }
        $now = Carbon::now();
        $duration = $row->started_at ? $now->diffInMilliseconds($row->started_at) : null;
        $row->update([
            'status'        => $status,
            'completed_at'  => $now,
            'duration_ms'   => $duration,
            'error_summary' => $errorSummary,
        ]);
        return true;
    }

    /**
     * Close every still-`in_progress` row for this session.
     * Used by the Stop hook (Claude Code only fires Stop once per session).
     */
    public static function closeSession(
        string $sessionId,
        string $status = SkillExecution::STATUS_COMPLETED,
        ?string $errorSummary = null,
    ): int {
        $now = Carbon::now();
        $rows = SkillExecution::where('session_id', $sessionId)
            ->where('status', SkillExecution::STATUS_IN_PROGRESS)
            ->get();

        foreach ($rows as $row) {
            $duration = $row->started_at ? $now->diffInMilliseconds($row->started_at) : null;
            $row->update([
                'status'        => $status,
                'completed_at'  => $now,
                'duration_ms'   => $duration,
                'error_summary' => $errorSummary ?: $row->error_summary,
            ]);
        }
        return $rows->count();
    }

    /** Mark `in_progress` rows older than $maxAgeSeconds as `orphaned`. */
    public static function sweepOrphaned(int $maxAgeSeconds = 7200): int
    {
        $cutoff = Carbon::now()->subSeconds($maxAgeSeconds);
        return SkillExecution::where('status', SkillExecution::STATUS_IN_PROGRESS)
            ->where('started_at', '<', $cutoff)
            ->update([
                'status'       => SkillExecution::STATUS_ORPHANED,
                'completed_at' => Carbon::now(),
            ]);
    }

    /**
     * Aggregate metrics. Returns array keyed by skill_name.
     *
     * @return array<string, array{
     *     applied:int,completed:int,failed:int,orphaned:int,interrupted:int,
     *     in_progress:int,completion_rate:float,failure_rate:float,
     *     last_used_at:?string
     * }>
     */
    public static function metrics(?Carbon $since = null, ?string $skillName = null): array
    {
        $q = SkillExecution::query();
        if ($since) $q->where('started_at', '>=', $since);
        if ($skillName) $q->where('skill_name', self::normaliseName($skillName));

        $rows = $q->select([
                'skill_name',
                'status',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('MAX(started_at) as last_used'),
            ])
            ->groupBy('skill_name', 'status')
            ->get();

        $bySkill = [];
        foreach ($rows as $r) {
            $name = $r->skill_name;
            if (!isset($bySkill[$name])) {
                $bySkill[$name] = [
                    'applied' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'orphaned' => 0,
                    'interrupted' => 0,
                    'in_progress' => 0,
                    'completion_rate' => 0.0,
                    'failure_rate' => 0.0,
                    'last_used_at' => null,
                ];
            }
            $cnt = (int) $r->cnt;
            $bySkill[$name]['applied'] += $cnt;
            if (isset($bySkill[$name][$r->status])) {
                $bySkill[$name][$r->status] = $cnt;
            }
            $last = $r->last_used;
            if ($last && (!$bySkill[$name]['last_used_at'] || $last > $bySkill[$name]['last_used_at'])) {
                $bySkill[$name]['last_used_at'] = (string) $last;
            }
        }
        foreach ($bySkill as $name => &$m) {
            if ($m['applied'] > 0) {
                $m['completion_rate'] = round($m['completed'] / $m['applied'], 4);
                $m['failure_rate']    = round($m['failed']    / $m['applied'], 4);
            }
        }
        return $bySkill;
    }

    /** Recent failed/orphaned executions for a skill. */
    public static function recentFailures(string $skillName, int $limit = 5): array
    {
        return SkillExecution::query()
            ->where('skill_name', self::normaliseName($skillName))
            ->whereIn('status', [
                SkillExecution::STATUS_FAILED,
                SkillExecution::STATUS_ORPHANED,
                SkillExecution::STATUS_INTERRUPTED,
            ])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private static function normaliseName(string $name): string
    {
        $name = trim($name);
        if ($name === '') return $name;
        // Allow "plugin:name" form; otherwise lowercase
        return strtolower($name);
    }
}
