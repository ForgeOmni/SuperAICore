<?php

namespace SuperAICore\AgentSpawn;

/**
 * Structured plan emitted by a backend that can't natively spawn sub-agents.
 * The preamble instructs the model to write this file (`_spawn_plan.json`)
 * in the run's output directory as its first-phase output.
 *
 * Preferred shape (minimal — the model doesn't have to JSON-escape any
 * multi-line agent markdown, which routinely trips smaller models like
 * Gemini Flash):
 *
 * {
 *   "version": 1,
 *   "concurrency": 4,
 *   "agents": [
 *     { "name": "cto-vogels",   "task_prompt": "...", "output_subdir": "cto-vogels" },
 *     { "name": "ceo-bezos",    "task_prompt": "...", "output_subdir": "ceo-bezos"  }
 *   ]
 * }
 *
 * The host resolves each agent's `system_prompt` by reading
 * `<agentsDir>/<name>.md` (typically `<projectRoot>/.claude/agents/`)
 * when {@see fromFile} is called with an `$agentsDir`. `output_subdir`
 * defaults to `name` when omitted.
 *
 * Legacy shape with an inline `system_prompt` string is still accepted —
 * useful for hosts that don't ship role files on disk — but discouraged
 * because embedding multi-line YAML-frontmatter markdown inside JSON
 * causes unescaped-quote / unescaped-newline parse failures.
 */
class SpawnPlan
{
    public function __construct(
        public readonly array $agents,
        public readonly int $concurrency = 4,
    ) {}

    /**
     * @param  string       $path        Absolute path to `_spawn_plan.json`.
     * @param  string|null  $agentsDir   Absolute path to the host's agents
     *         directory (e.g. `<projectRoot>/.claude/agents`). When a plan
     *         entry omits `system_prompt` (the preferred shape), we resolve
     *         it by reading `<agentsDir>/<name>.md`. Null keeps the legacy
     *         "must-be-embedded" behavior.
     */
    public static function fromFile(string $path, ?string $agentsDir = null): ?self
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

        // Last-ditch recovery: the model embedded agent markdown with
        // unescaped double-quotes (e.g. YAML frontmatter `description: "..."`),
        // which no amount of control-char re-escaping can fix — the JSON
        // parser has no way to tell which `"` is meant as the string
        // terminator. Salvage just the `name` fields with regex and let
        // the caller reconstruct `system_prompt` from disk by name.
        if (!is_array($json) || empty($json['agents']) || !is_array($json['agents'])) {
            $salvage = self::salvageNamesOnly($raw);
            if ($salvage === null) return null;
            $json = $salvage;
        }

        $agents = [];
        foreach ($json['agents'] as $a) {
            if (!is_array($a) || empty($a['name'])) continue;

            $systemPrompt = (string) ($a['system_prompt'] ?? '');
            if ($systemPrompt === '' && $agentsDir !== null) {
                $systemPrompt = self::loadAgentDefinition($agentsDir, (string) $a['name']);
            }

            // task_prompt is strongly preferred but not strictly required —
            // if we had to salvage the plan, we still want the child to run
            // against the role definition so the consolidation pass has
            // something to read. Pass '' when absent; ChildRunner turns
            // that into "just the system_prompt" with a trailing separator.
            $taskPrompt = (string) ($a['task_prompt'] ?? '');

            $agents[] = [
                'name' => (string) $a['name'],
                'system_prompt' => $systemPrompt,
                'task_prompt' => $taskPrompt,
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
     * Read `<agentsDir>/<name>.md`. Returns the raw markdown (frontmatter
     * + body). Missing / unreadable files resolve to '' so the child still
     * runs against just the task_prompt — that's better than the whole
     * run bailing because one role file is missing.
     */
    protected static function loadAgentDefinition(string $agentsDir, string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
        if ($safe === '') return '';
        $path = rtrim($agentsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe . '.md';
        if (!is_file($path) || !is_readable($path)) return '';
        return (string) @file_get_contents($path);
    }

    /**
     * Pull just the agent `name`s out of a malformed plan with a regex.
     * Used when `json_decode` can't recover — e.g. the model embedded
     * markdown with unescaped quotes. Returns a minimal plan-shaped array
     * so `fromFile()` can resolve system_prompt from disk by name.
     *
     * @return array{agents:array<int,array{name:string}>,concurrency:int}|null
     */
    protected static function salvageNamesOnly(string $raw): ?array
    {
        if (!preg_match_all('/"name"\s*:\s*"([A-Za-z0-9._-]+)"/', $raw, $m)) {
            return null;
        }
        $names = array_values(array_unique($m[1]));
        if (empty($names)) return null;

        $agents = [];
        foreach ($names as $n) $agents[] = ['name' => $n];

        $concurrency = 4;
        if (preg_match('/"concurrency"\s*:\s*(\d+)/', $raw, $c)) {
            $concurrency = (int) $c[1];
        }
        return ['agents' => $agents, 'concurrency' => $concurrency];
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
