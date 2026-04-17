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
        $config = ['mcpServers' => []];
        foreach ($servers as $s) {
            if (empty($s['key'])) continue;
            $config['mcpServers'][$s['key']] = array_filter([
                'command' => $s['command'] ?? null,
                'args' => $s['args'] ?? [],
                'env' => $s['env'] ?? new \stdClass(),
            ]);
        }
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
- `Agent` with `subagent_type` → **You have no native sub-agent tool.** When the skill asks you to "spawn N agents in parallel", do NOT fake it with `codebase_investigator`. Instead, play all roles yourself sequentially and still produce the per-role output files the skill requires.

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
