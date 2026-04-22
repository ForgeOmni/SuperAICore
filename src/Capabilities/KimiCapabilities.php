<?php

namespace SuperAICore\Capabilities;

use SuperAICore\AgentSpawn\SpawnPlan;
use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * Kimi Code CLI adapter (MoonshotAI/kimi-cli, binary `kimi`).
 *
 * **Architectural posture — (a) native / (b) fallback, operator-chosen.**
 * Kimi CLI has a native `Agent` tool that does parallel fanout in one
 * assistant turn (Claude 2.x-style), plus built-in `coder` / `explore` /
 * `plan` subagents. Two integration modes, picked per-run:
 *
 *   (a) Default — let Kimi drive its own `Agent` dispatch. `spawnPreamble()`
 *       and `consolidationPrompt()` both return `''`, so `AgentSpawn\Pipeline`
 *       fast-exits without injecting our three-phase protocol. Same posture
 *       as Claude / Kiro.
 *
 *   (b) Opt-in — force Kimi through our `Orchestrator` + the 0.6.8
 *       weak-model hardening (host-injected guards, canonical
 *       `output_subdir`, premature-consolidator cleanup, `auditAgentOutput`,
 *       language-aware consolidation). Turned on by setting
 *       `super-ai-core.backends.kimi_cli.use_native_agents = false`, or its
 *       env equivalent `AI_CORE_KIMI_USE_NATIVE_AGENTS=false`.
 *
 * Reasons to force (b): per-child stream observability (Kimi's stream-json
 * hides `SubagentEvent` in the root stream), tasks exceeding Kimi's
 * 500-steps-per-turn cap, or when the caller wants the standard
 * 摘要 / 思维导图 / 流程图 triple produced by our consolidation prompt.
 *
 * **Tool-name translation.** Kimi's built-in tools use PascalCase
 * (`ReadFile` / `WriteFile` / `StrReplaceFile` / `Shell` / …) rather than
 * Claude Code's bare names (`Read` / `Write` / `Edit` / `Bash`). Map below
 * lets skill/agent authors write once in Claude conventions.
 *
 * **Skills/agents.** Verified 2026-04-22 on kimi v1.38.0:
 *   - `.claude/skills/` IS auto-loaded natively — zero translation needed.
 *   - `.claude/agents/*.md` is NOT auto-loaded — Kimi uses its own YAML
 *     agent format under `~/.kimi/agents/<name>/agent.yaml`. A
 *     `KimiAgentSync` writer to bridge the formats is deferred to MVP-3;
 *     the (b) fallback path here doesn't need it, since we spawn
 *     `kimi --print` as an unstructured child and supply the agent's
 *     system prompt via the preamble text.
 *
 * **MCP.** Kimi reads `~/.kimi/mcp.json` (+ project `.mcp.json`) in the
 * Claude-compatible shape. `renderMcpConfig()` emits the user-scope file
 * for `McpManager::syncAllBackends()` fan-out, preserving non-`mcpServers`
 * keys on disk so any Kimi-specific config the user pasted in stays put.
 */
class KimiCapabilities implements BackendCapabilities
{
    public function key(): string
    {
        return AiProvider::BACKEND_KIMI;
    }

    public function toolNameMap(): array
    {
        return [
            'Read'      => 'ReadFile',
            'Write'     => 'WriteFile',
            'Edit'      => 'StrReplaceFile',
            'MultiEdit' => 'StrReplaceFile',
            'Bash'      => 'Shell',
            'Glob'      => 'Glob',
            'Grep'      => 'Grep',
            'WebFetch'  => 'FetchURL',
            'WebSearch' => 'SearchWeb',
            // `Agent` maps to Kimi's native `Agent` tool — same semantics
            // (parallel fanout), no translation needed.
        ];
    }

    public function supportsSubAgents(): bool
    {
        return true;
    }

    public function supportsMcp(): bool
    {
        return true;
    }

    public function streamFormat(): string
    {
        return 'stream-json';
    }

    public function mcpConfigPath(): ?string
    {
        return '.kimi/mcp.json';
    }

    public function transformPrompt(string $prompt): string
    {
        // Auto-inject the three-phase plan-emitter preamble only when
        // fallback (b) is active. (a) default leaves the prompt untouched
        // so native Agent dispatch stays verbatim — Kimi reads `.claude/
        // skills/` natively, and tool names are documented, so no Claude
        // → Kimi pre-processing is needed for the common path.
        if ($this->useNativeAgents()) {
            return $prompt;
        }
        if (str_contains($prompt, '<!-- kimi-preamble-v1 -->')) {
            return $prompt;
        }
        return self::PREAMBLE . $prompt;
    }

    public function renderMcpConfig(array $servers): string
    {
        // Kimi accepts the same `mcpServers` shape as Claude's .mcp.json.
        // Merge into whatever else the user has in ~/.kimi/mcp.json
        // (auth tokens, project-specific overrides) so we don't clobber.
        $existing = [];
        $path = self::homeDir() . '/.kimi/mcp.json';
        if (is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $existing['mcpServers'] = [];
        foreach ($servers as $s) {
            if (empty($s['key'])) continue;
            $entry = [
                'type'    => $s['type']    ?? 'stdio',
                'command' => $s['command'] ?? null,
            ];
            if (!empty($s['args']))  $entry['args'] = $s['args'];
            if (!empty($s['env']))   $entry['env']  = $s['env'];
            $existing['mcpServers'][$s['key']] = array_filter(
                $entry,
                static fn ($v) => $v !== null && $v !== '',
            );
        }

        return json_encode(
            $existing,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    public function spawnPreamble(string $outputDir): string
    {
        return $this->useNativeAgents() ? '' : self::PREAMBLE;
    }

    public function consolidationPrompt(SpawnPlan $plan, array $report, string $outputDir): string
    {
        // Symmetrical with spawnPreamble — returning empty makes Pipeline
        // fast-exit, cementing the (a) default as "no three-phase
        // orchestration". When operators flip `use_native_agents=false`,
        // both methods start returning substantive strings together.
        if ($this->useNativeAgents()) {
            return '';
        }
        return SpawnConsolidationPrompt::build($plan, $report, $outputDir);
    }

    /**
     * Read the (a)/(b) toggle from host config. Default `true` — Kimi
     * drives its own Agent fanout. Flip to `false` to route through our
     * three-phase `AgentSpawn\Pipeline`, inheriting the 0.6.8 weak-model
     * hardening surface.
     */
    public function useNativeAgents(): bool
    {
        if (!function_exists('config')) return true;
        try {
            return (bool) config(
                'super-ai-core.backends.kimi_cli.use_native_agents',
                true,
            );
        } catch (\Throwable) {
            return true;
        }
    }

    protected static function homeDir(): string
    {
        return rtrim(getenv('HOME') ?: (getenv('USERPROFILE') ?: ''), '/\\');
    }

    /**
     * Plan-emitter preamble. Idempotent via the `<!-- kimi-preamble-v1 -->`
     * sentinel. Only injected when (b) fallback is on; on (a) default
     * Kimi's native `Agent` tool handles dispatch directly without this.
     *
     * The shape is deliberately close to `GeminiCapabilities::PREAMBLE` —
     * the plan file shape is the same, and the same host-injected per-agent
     * guard clauses (0.6.8 `SpawnPlan::appendGuards`) are applied to every
     * `task_prompt` the plan emits, regardless of which backend wrote the
     * plan. The tool-name section differs because Kimi's tools are named
     * ReadFile / WriteFile / StrReplaceFile / Shell rather than
     * Gemini's read_file / write_file / replace / run_shell_command.
     */
    const PREAMBLE = <<<'TXT'
<!-- kimi-preamble-v1 -->
## Runtime: Kimi CLI — Spawn Plan Mode

You are running under the Kimi CLI runtime. If the task below tells you to
spawn / assemble / dispatch N agents, your job here is to **write a plan
file and stop** — the host will fan out the real child processes in
parallel and then call you back to consolidate.

## Tool Name Mapping (Kimi equivalents)

- `Read` → `ReadFile`
- `Write` → `WriteFile`
- `Edit` / `MultiEdit` → `StrReplaceFile`
- `Bash` → `Shell`
- `WebFetch` → `FetchURL`
- `WebSearch` → `SearchWeb`
- `Glob` / `Grep` — same name

## Spawn Plan Protocol

**Step 1.** Decide which agents to spawn (2–5 unless the skill says
otherwise). You do NOT need to read `.claude/agents/<name>.md` — the host
loads each role file from disk by `name` when it fans out the children.
Save your context budget.

**Step 2.** Write `_spawn_plan.json` in the output directory using the
**absolute path** from the skill's output-directory rule. Never use a bare
relative path — Kimi's cwd may not match the sandbox dir, so the plan
would land in the wrong place.

Keep the plan MINIMAL. Emit only `name` (the subagent identifier, e.g.
`ceo-bezos`), `task_prompt` (the run-specific instructions as ONE string),
and optionally `output_subdir`. Do NOT embed the full role markdown in a
`system_prompt` field — JSON-escaping multi-line YAML frontmatter
routinely produces broken JSON the host can't parse.

Exact shape:

```json
{
  "version": 1,
  "concurrency": 4,
  "agents": [
    {
      "name": "ceo-bezos",
      "task_prompt": "Specific instructions for THIS agent on THIS task. Include the absolute output dir, $LANGUAGE, and the skill's per-agent budget (e.g. 1 md + 1 data). Keep it to a few hundred characters.",
      "output_subdir": "ceo-bezos"
    },
    { "name": "legal-lessig", "task_prompt": "...", "output_subdir": "legal-lessig" }
  ]
}
```

JSON hygiene before you call `WriteFile`:
- Every string uses `"..."` with no literal newlines; use `\n` escapes
- No unescaped `"` inside a string — use `'...'` or 「」
- No trailing commas, no comments

**Step 3.** After writing the plan, stop. Do NOT play the roles yourself.
Reply with a one-line confirmation like `Plan emitted: N agents.` and end.

When the host re-invokes you for **consolidation**, you will see each
agent's output files already present in their `output_subdir`. Read them
via `ReadFile`, then write the final summary / 思维导图 / 流程图 files
exactly as the skill requires.

---

TXT;
}
