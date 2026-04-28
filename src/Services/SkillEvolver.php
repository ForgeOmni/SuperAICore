<?php

namespace SuperAICore\Services;

use SuperAICore\Models\SkillEvolutionCandidate;
use SuperAICore\Models\SkillExecution;
use SuperAICore\Registry\SkillRegistry;

/**
 * FIX-mode skill evolver. Inspired by OpenSpace's `skill_engine/evolver.py`,
 * but trimmed to the safe subset:
 *
 *   ✓ FIX     — propose an in-place patch for an existing skill
 *   ✗ DERIVED  — disabled (Day 0: humans curate new skills)
 *   ✗ CAPTURED — disabled (Day 0: humans curate new skills)
 *
 * The evolver never modifies SKILL.md directly. It only generates a
 * *candidate*: an LLM prompt + (optionally) a proposed diff. A human then
 * reviews via `php artisan skill:candidates` and decides to apply or reject.
 */
class SkillEvolver
{
    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly ?Dispatcher $dispatcher = null,
    ) {}

    /**
     * Propose a FIX candidate for $skillName.
     *
     * Reads recent failures from telemetry, builds the prompt, optionally
     * invokes the LLM via Dispatcher, and persists a SkillEvolutionCandidate.
     * Returns the candidate model.
     */
    public function proposeFix(
        string $skillName,
        string $triggerType = SkillEvolutionCandidate::TRIGGER_MANUAL,
        ?int $executionId = null,
        bool $dispatch = false,
    ): SkillEvolutionCandidate {
        $skill = $this->registry->get($skillName);
        if (!$skill) {
            throw new \InvalidArgumentException("Skill '{$skillName}' not found in registry.");
        }

        $failures = SkillTelemetry::recentFailures($skillName, 5);
        $metrics  = SkillTelemetry::metrics(null, $skillName)[$skillName] ?? null;

        $prompt = $this->buildPrompt($skill, $failures, $metrics);

        $rationale = $this->summariseRationale($metrics, $failures);

        $candidate = SkillEvolutionCandidate::create([
            'skill_name'    => strtolower($skillName),
            'trigger_type'  => $triggerType,
            'execution_id'  => $executionId,
            'status'        => SkillEvolutionCandidate::STATUS_PENDING,
            'rationale'     => $rationale,
            'llm_prompt'    => $prompt,
            'context'       => [
                'skill_path' => $skill->path,
                'failures'   => array_map(static fn($f) => [
                    'id' => $f['id'] ?? null,
                    'status' => $f['status'] ?? null,
                    'started_at' => (string) ($f['started_at'] ?? ''),
                    'error_summary' => $f['error_summary'] ?? null,
                ], $failures),
                'metrics'    => $metrics,
            ],
        ]);

        if ($dispatch && $this->dispatcher) {
            $this->dispatchAndStoreDiff($candidate, $prompt);
        }

        return $candidate->fresh();
    }

    /**
     * Sweep skills with degraded metrics and queue candidates for them.
     * Returns array of created candidate ids.
     *
     * @param float $failureRateThreshold  trigger when failure_rate > this
     * @param int   $minApplied            require at least this many runs
     */
    public function sweepDegraded(
        float $failureRateThreshold = 0.30,
        int $minApplied = 5,
    ): array {
        $created = [];
        $allMetrics = SkillTelemetry::metrics();
        foreach ($allMetrics as $skillName => $m) {
            if ($m['applied'] < $minApplied) continue;
            if ($m['failure_rate'] <= $failureRateThreshold) continue;
            // De-dup: skip if a pending candidate already exists.
            $existing = SkillEvolutionCandidate::where('skill_name', strtolower($skillName))
                ->where('status', SkillEvolutionCandidate::STATUS_PENDING)
                ->exists();
            if ($existing) continue;
            try {
                $c = $this->proposeFix(
                    $skillName,
                    SkillEvolutionCandidate::TRIGGER_METRIC_DEGRADATION,
                    null,
                    false,
                );
                $created[] = $c->id;
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $created;
    }

    private function buildPrompt(
        \SuperAICore\Registry\Skill $skill,
        array $failures,
        ?array $metrics,
    ): string {
        $body = (string) ($skill->body ?? '');
        $bodyTrimmed = mb_strlen($body) > 8000 ? mb_substr($body, 0, 8000) . "\n\n…[truncated]" : $body;
        $name = $skill->name;
        $desc = $skill->description ?? '(no description)';

        $metricsLine = $metrics
            ? sprintf(
                "applied=%d  completed=%d  failed=%d  failure_rate=%.2f  last_used=%s",
                $metrics['applied'] ?? 0,
                $metrics['completed'] ?? 0,
                $metrics['failed'] ?? 0,
                $metrics['failure_rate'] ?? 0,
                $metrics['last_used_at'] ?? 'n/a',
            )
            : 'no telemetry available';

        $failuresBlock = '';
        if ($failures) {
            $failuresBlock .= "## Recent Failures\n\n";
            foreach ($failures as $i => $f) {
                $idx = $i + 1;
                $when = $f['started_at'] ?? '';
                $st = $f['status'] ?? '';
                $err = $f['error_summary'] ?? '(no error captured)';
                $failuresBlock .= "### {$idx}. {$when} — {$st}\n```\n{$err}\n```\n\n";
            }
        }

        return <<<PROMPT
# Task: Propose a FIX-mode patch for skill `{$name}`

You are evolving a Claude Code Skill that has been failing or under-performing.
Your job is **not** to rewrite it from scratch — produce the **smallest possible
patch** that addresses the failure pattern. If you cannot identify a concrete
root cause from the evidence below, reply with `NO_FIX_RECOMMENDED` and a
one-paragraph explanation.

## Skill Header
- name: {$name}
- description: {$desc}
- path: {$skill->path}
- metrics: {$metricsLine}

## Current SKILL.md body

```markdown
{$bodyTrimmed}
```

{$failuresBlock}

## Output Format

Return exactly two sections, in this order:

### Section 1 — Diagnosis
Two-to-four sentences. Name the failure mode and the smallest change that
addresses it. Do not invent failures the evidence does not support.

### Section 2 — Patch
A single fenced \`\`\`diff block in unified-diff format with `a/` and `b/`
prefixes, OR `NO_FIX_RECOMMENDED` if no safe patch exists.

Keep edits surgical. Do not restructure sections, rename the skill, change
the frontmatter `name`, or add new tools to `allowed-tools` unless the
failure evidence explicitly demands it.
PROMPT;
    }

    private function summariseRationale(?array $metrics, array $failures): string
    {
        $parts = [];
        if ($metrics) {
            $parts[] = sprintf(
                'failure_rate=%.2f over %d runs',
                $metrics['failure_rate'] ?? 0,
                $metrics['applied'] ?? 0,
            );
        }
        if ($failures) {
            $parts[] = sprintf('%d recent failure(s)', count($failures));
            $first = $failures[0]['error_summary'] ?? null;
            if ($first) {
                $parts[] = 'last_error="' . mb_substr($first, 0, 120) . '"';
            }
        }
        return $parts ? implode(' · ', $parts) : 'manual trigger';
    }

    private function dispatchAndStoreDiff(SkillEvolutionCandidate $candidate, string $prompt): void
    {
        try {
            $result = $this->dispatcher->dispatch([
                'capability' => 'reasoning',
                'task_type'  => 'skill_evolution_fix',
                'prompt'     => $prompt,
            ]);
            if (!$result || !isset($result['text'])) return;
            $text = (string) $result['text'];

            $diff = $this->extractDiffBlock($text);
            $candidate->update([
                'proposed_body' => $text,
                'proposed_diff' => $diff,
            ]);
        } catch (\Throwable $e) {
            // Silent — caller checks proposed_diff to know if dispatch worked.
        }
    }

    private function extractDiffBlock(string $text): ?string
    {
        if (preg_match('/```diff\s*\n(.+?)\n```/s', $text, $m)) {
            return $m[1];
        }
        if (str_contains($text, 'NO_FIX_RECOMMENDED')) {
            return null;
        }
        return null;
    }
}
