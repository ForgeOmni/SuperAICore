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

**Step 1.** Decide which agents to spawn (2–5 unless the skill says otherwise). For each one, read its role definition from `.claude/agents/<agent-name>.md` via `read_file`.

**Step 2.** Write `_spawn_plan.json` in the output directory using the **absolute path** from the skill's output-directory rule (the one in the SECURITY SANDBOX block later in this prompt). Never use a bare relative path like `_spawn_plan.json` — gemini-cli's cwd is the project root, not the sandbox dir, so the plan would land in the wrong place.

Exact shape:

```json
{
  "version": 1,
  "concurrency": 4,
  "agents": [
    {
      "name": "ceo-bezos",
      "system_prompt": "...full contents of .claude/agents/ceo-bezos.md...",
      "task_prompt": "Specific instructions for THIS agent on THIS task — include the output dir, language requirement, and any methodology the skill requires (research keywords, CSV format, etc.).",
      "output_subdir": "ceo-bezos"
    },
    { "name": "legal-lessig", "system_prompt": "...", "task_prompt": "...", "output_subdir": "legal-lessig" }
  ]
}
```

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
