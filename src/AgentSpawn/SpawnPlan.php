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
        if ($json === null) {
            // Models (gemini-cli especially) sometimes emit raw \n / \t / \r
            // inside string values, which JSON spec forbids. Re-escape those
            // control chars and try again before giving up.
            $cleaned = self::reescapeControlCharsInJsonStrings($raw);
            $json = json_decode($cleaned, true);
        }
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

    /**
     * Walk the input char-by-char; inside "..." string literals, replace
     * raw \n / \r / \t with their JSON-escaped forms. Outside string
     * literals leaves everything alone. Models sometimes emit unescaped
     * control chars inside string values, which json_decode rejects
     * strictly — this re-escape lets us recover.
     */
    protected static function reescapeControlCharsInJsonStrings(string $raw): string
    {
        $out = '';
        $len = strlen($raw);
        $inString = false;
        $escaped = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($escaped) {
                $out .= $c;
                $escaped = false;
                continue;
            }
            if ($c === '\\') {
                $out .= $c;
                $escaped = true;
                continue;
            }
            if ($c === '"') {
                $inString = !$inString;
                $out .= $c;
                continue;
            }
            if ($inString) {
                if ($c === "\n") { $out .= '\\n'; continue; }
                if ($c === "\r") { $out .= '\\r'; continue; }
                if ($c === "\t") { $out .= '\\t'; continue; }
                if (ord($c) < 0x20) { $out .= sprintf('\\u%04x', ord($c)); continue; }
            }
            $out .= $c;
        }
        return $out;
    }
}
