<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * Gemini CLI adapter.
 *
 * Exposes web_fetch + google_web_search, has no native Agent-spawn tool,
 * and defaults to `codebase_investigator` on unknown tasks — producing
 * meta-analyses of the cwd instead of the actual research subject.
 *
 * This class handles the tool-name translation and the mandatory behavior
 * preamble that steers Gemini back to the right tools.
 */
class GeminiCapabilities implements BackendCapabilities
{
    public function key(): string { return AiProvider::BACKEND_GEMINI; }

    public function toolNameMap(): array
    {
        return [
            'WebSearch' => 'google_web_search',
            'WebFetch'  => 'web_fetch',
            'Read'      => 'read_file',
            'Write'     => 'write_file',
            'Edit'      => 'replace',
            'Glob'      => 'glob',
            'Grep'      => 'grep_search',
            'Bash'      => 'run_shell_command',
            // Agent has no direct equivalent — see transformPrompt().
        ];
    }

    public function supportsSubAgents(): bool { return false; }
    public function supportsMcp(): bool { return true; }
    public function streamFormat(): string { return 'stream-json'; }
    public function mcpConfigPath(): ?string { return '.gemini/settings.json'; }

    public function transformPrompt(string $prompt): string
    {
        // Idempotent — don't double-inject if this prompt was already adapted.
        if (str_contains($prompt, '<!-- gemini-preamble-v1 -->')) {
            return $prompt;
        }
        return self::PREAMBLE . $prompt;
    }

    public function renderMcpConfig(array $servers): string
    {
        // Gemini uses the same `mcpServers` key as Claude.
        // Merge into whatever else the user has in settings.json (auth,
        // theme, model, etc.) so we don't clobber it — the sync layer
        // passes the file's prior content via the $servers wrapper when
        // available, but as a guard we also try to read from disk.
        $existing = [];
        $path = self::homeDir() . '/.gemini/settings.json';
        if (is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) $existing = $decoded;
        }

        $existing['mcpServers'] = [];
        foreach ($servers as $s) {
            if (empty($s['key'])) continue;
            $existing['mcpServers'][$s['key']] = array_filter([
                'command' => $s['command'] ?? null,
                'args' => $s['args'] ?? [],
                'env' => $s['env'] ?? new \stdClass(),
            ]);
        }
        return json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected static function homeDir(): string
    {
        return rtrim(getenv('HOME') ?: (getenv('USERPROFILE') ?: ''), '/\\');
    }

    public function spawnPreamble(string $outputDir): string
    {
        // Same text transformPrompt() injects, exposed as a separate
        // method so Pipeline / direct callers can render the preamble
        // without going through the full transform.
        return self::PREAMBLE;
    }

    public function consolidationPrompt(\SuperAICore\AgentSpawn\SpawnPlan $plan, array $report, string $outputDir): string
    {
        return SpawnConsolidationPrompt::build($plan, $report, $outputDir);
    }

    const PREAMBLE = <<<'TXT'
<!-- gemini-preamble-v1 -->
## Runtime: Gemini CLI — Tool Name Mapping

You are running under the Gemini CLI runtime. The task instructions below reference Claude Code tool names; use these Gemini equivalents instead:

- `WebSearch` → **`google_web_search`** (perform external web searches)
- `WebFetch`  → **`web_fetch`** (fetch and read a URL)
- `Read`      → **`read_file`**
- `Write`     → **`write_file`**
- `Glob`      → **`glob`**
- `Grep`      → **`grep_search`**
- `Bash`      → **`run_shell_command`**
- `Agent` with `subagent_type` → **You have no native sub-agent tool.** Do NOT fake it with `codebase_investigator` or try to play all roles yourself. Instead, use the **Spawn Plan protocol** described below.

## Spawn Plan Protocol (when a skill asks to "spawn N agents in parallel")

If the skill tells you to spawn / assemble / dispatch N agents, your job here is to **write a plan file and stop** — the host will fan out the real child processes in parallel and then call you back to consolidate.

**Step 1.** Decide which agents to spawn (2–5 unless the skill says otherwise). You do **NOT** need to read `.claude/agents/<name>.md` — the host loads each role file from disk by `name` when it fans out the children. Save your context budget.

**Step 2.** Write `_spawn_plan.json` in the output directory using the **absolute path** from the skill's output-directory rule (the one in the SECURITY SANDBOX block later in this prompt). Never use a bare relative path like `_spawn_plan.json` — gemini-cli's cwd is the project root, not the sandbox dir, so the plan would land in the wrong place.

**Keep the plan MINIMAL.** Emit only `name` (the subagent identifier, e.g. `ceo-bezos`), `task_prompt` (the run-specific instructions as ONE string), and optionally `output_subdir`. **Do NOT embed the full role markdown in a `system_prompt` field** — that forces you to JSON-escape multi-line content with YAML frontmatter quotes, which routinely produces broken JSON the host can't parse (every `"` inside an embedded `description: "..."` line ends the JSON string early). The host loads `.claude/agents/<name>.md` itself.

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

JSON hygiene checklist before you call `write_file`:
- Every string uses `"..."` with **no literal newlines** inside — use spaces or `\n` escapes only.
- **No unescaped `"`** inside a string — if you must quote something, use single quotes `'...'` or Chinese 「」/『』 instead.
- No trailing commas, no comments.

If unsure, **emit shorter `task_prompt` strings** — a terse plan that parses beats a rich plan that doesn't.

**MANDATORY per-agent guard clauses.** Every `task_prompt` you emit MUST embed these four rules verbatim (translate them if `$LANGUAGE` is not English — the rules must be in the same language the agent will reply in, otherwise Gemini Flash defaults the "technical" bits back to English):

1. **Stay in your lane.** You are exactly ONE agent (`<name>`). Do NOT create sibling-role sub-directories (e.g. if you are `regional-khanna`, do NOT make `ceo/`, `cfo/`, `marketing/` inside your output dir). Do NOT write reports as if you were another agent. Only the files listed in your output budget, inside your own `output_subdir`.
2. **Consolidation is not your job.** Do NOT emit `summary.md` / `摘要.md` / `思维导图.md` / `流程图.md` / `mindmap.md` / `flowchart.md` / or any name the skill reserves for the consolidator. The host re-invokes the parent backend for consolidation — those files come from that pass.
3. **Language uniformity.** Every file you Write — the markdown report, the CSV (including column headers and non-proper-noun cell values), the `_signals/<name>.md` Findings Board, code comments — must be in `$LANGUAGE`. Proper nouns (company names, product names, URLs) can stay in their original form. CSV numbers stay numeric. Do NOT switch to English for "technical" sections or protocol blocks.
4. **File extension whitelist.** Only `.md`, `.csv`, `.png`. NO `.py` / `.sh` / `.json` / `.txt` / `.html`. If you need a chart, render it to PNG directly (via mmdc or similar) and write the PNG — do NOT leave helper scripts behind.

When you inject the IAP (Inter-Agent Protocol) snippet into a `task_prompt`, translate the whole snippet (`Self-consult`, `Findings Board`, `Cross-domain signals`, etc.) into `$LANGUAGE` too. Mixed-language prompts bias Flash toward writing the "English-looking" artifacts (CSV, `_signals/*.md`) in English — which breaks rule #3.

**Step 3.** After writing the plan, stop. Do NOT play the roles yourself. Reply with a one-line confirmation like `Plan emitted: N agents.` and end.

When the host re-invokes you for **consolidation**, you will see each agent's output files already present in their `output_subdir`. Read them via `read_file`, then write the final summary / 思维导图 / 流程图 files as the skill requires.

## Mandatory Behavior for External-Research Tasks

If the task involves a URL, domain, company, website, or any subject that lives OUTSIDE this repository (e.g. "investigate forgeomni.com", "research market X", "check company Y"):

1. **Do NOT run `codebase_investigator` as a shortcut.** That tool analyzes the LOCAL codebase — it cannot see the external subject.
2. **Always start with `google_web_search`** using 3+ keyword combinations in the relevant language, then **`web_fetch`** the most promising URLs to read their actual content.
3. Cite every fact with a source URL; confidence mark as ✅ verified / ⚠️ single source / ❓ inferred.
4. If the task has an output directory, write the resulting `.md` / `.csv` files there. Never skip file writes.

Only use `codebase_investigator` / `read_file` / `grep_search` when the task explicitly asks about THIS repository's code, configuration, or documentation.

---

TXT;
}
