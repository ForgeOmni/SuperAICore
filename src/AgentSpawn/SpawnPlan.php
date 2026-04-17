<?php

namespace SuperAICore\AgentSpawn;

/**
 * Structured plan emitted by a backend that can't natively spawn sub-agents.
 * The preamble instructs the model to write this file (`_spawn_plan.json`)
 * in the run's output directory as its first-phase output.
 *
 * Shape on disk:
 * {
 *   "version": 1,
 *   "concurrency": 4,
 *   "agents": [
 *     {
 *       "name": "cto-vogels",          // subagent_type from the skill
 *       "system_prompt": "...",        // full role definition (from .claude/agents/cto-vogels.md)
 *       "task_prompt": "...",          // role-specific instructions for THIS run
 *       "output_subdir": "cto-vogels"  // relative to the run's output dir
 *     },
 *     ...
 *   ]
 * }
 */
class SpawnPlan
{
    public function __construct(
        public readonly array $agents,
        public readonly int $concurrency = 4,
    ) {}

    public static function fromFile(string $path): ?self
    {
        if (!is_file($path) || !is_readable($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['agents']) || !is_array($json['agents'])) return null;

        $agents = [];
        foreach ($json['agents'] as $a) {
            if (!is_array($a) || empty($a['name']) || empty($a['task_prompt'])) continue;
            $agents[] = [
                'name' => (string) $a['name'],
                'system_prompt' => (string) ($a['system_prompt'] ?? ''),
                'task_prompt' => (string) $a['task_prompt'],
                'output_subdir' => (string) ($a['output_subdir'] ?? $a['name']),
            ];
        }
        if (empty($agents)) return null;

        return new self(
            agents: $agents,
            concurrency: max(1, min(8, (int) ($json['concurrency'] ?? 4))),
        );
    }
}
