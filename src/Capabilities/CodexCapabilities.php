<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * Codex CLI adapter. Codex-rs exposes a shell tool + file tools but has
 * no native web-search / sub-agent primitives; skills targeting those
 * need to be told to plan differently (or work via MCPs).
 */
class CodexCapabilities implements BackendCapabilities
{
    public function key(): string { return AiProvider::BACKEND_CODEX; }

    public function toolNameMap(): array
    {
        // Codex tool names don't publicly deviate much — skills that call
        // Write/Read/Edit/Bash work. WebSearch/WebFetch need MCPs.
        return [];
    }

    public function supportsSubAgents(): bool { return false; }
    public function supportsMcp(): bool { return true; }
    public function streamFormat(): string { return 'stream-json'; }
    public function mcpConfigPath(): ?string { return '.codex/config.toml'; }

    public function transformPrompt(string $prompt): string
    {
        if (str_contains($prompt, '<!-- codex-preamble-v1 -->')) {
            return $prompt;
        }
        return self::PREAMBLE . $prompt;
    }

    /**
     * Codex reads TOML. We replace all `[mcp_servers.*]` blocks in the
     * existing config.toml and keep everything else (model, approvals,
     * projects, user's custom keys) as-is. Non-destructive merge.
     */
    public function renderMcpConfig(array $servers): string
    {
        $path = (getenv('HOME') ?: '') . '/.codex/config.toml';
        $existing = (is_file($path) && is_readable($path)) ? (string) @file_get_contents($path) : '';

        // Strip every existing [mcp_servers.*] block (and its contents up to
        // the next top-level section or EOF).
        $stripped = preg_replace('/^\[mcp_servers(\.[^\]]+)?\].*?(?=^\[(?!mcp_servers)|\z)/sm', '', $existing);
        $stripped = rtrim((string) $stripped) . "\n";

        $mcp = '';
        foreach ($servers as $s) {
            if (empty($s['key']) || empty($s['command'])) continue;
            $mcp .= "[mcp_servers.{$s['key']}]\n";
            $mcp .= 'command = ' . self::tomlString($s['command']) . "\n";
            if (!empty($s['args']) && is_array($s['args'])) {
                $mcp .= 'args = [' . implode(', ', array_map(fn ($a) => self::tomlString((string) $a), $s['args'])) . "]\n";
            }
            if (!empty($s['env']) && is_array($s['env'])) {
                $mcp .= "[mcp_servers.{$s['key']}.env]\n";
                foreach ($s['env'] as $k => $v) {
                    $mcp .= "{$k} = " . self::tomlString((string) $v) . "\n";
                }
            }
            $mcp .= "\n";
        }

        return $stripped . ($mcp === '' ? '' : "\n" . $mcp);
    }

    protected static function tomlString(string $value): string
    {
        // Double-quoted TOML string with basic escaping.
        return '"' . addcslashes($value, "\"\\\n\r\t") . '"';
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
<!-- codex-preamble-v1 -->
## Runtime: Codex CLI

You are running under OpenAI codex-rs. Tool names follow the standard Read/Write/Edit/Bash set — no translation needed.

**Agent spawning — Spawn Plan protocol**: codex has no native sub-agent tool. Do NOT play all roles yourself sequentially. Instead, when a skill tells you to spawn / assemble / dispatch N agents:

1. You do **NOT** need to read `.claude/agents/<name>.md` — the host loads each role file from disk by `name` when it fans out the children.
2. Write `_spawn_plan.json` in the output directory using the **absolute path** from the skill's output-directory rule. Never use a bare relative path — codex's cwd is the project root, so the plan would land in the wrong place. Keep the plan MINIMAL — `name`, `task_prompt`, optional `output_subdir`. **Do NOT embed the full role markdown in a `system_prompt` field** — multi-line YAML-frontmatter strings frequently ship with unescaped quotes that corrupt the JSON; the host already has the role file on disk.
   ```json
   {
     "version": 1, "concurrency": 4,
     "agents": [
       { "name": "ceo-bezos", "task_prompt": "specific instructions — include absolute output dir, $LANGUAGE, and the per-agent output budget the skill defined", "output_subdir": "ceo-bezos" },
       ...
     ]
   }
   ```
   JSON hygiene before `write`: no literal newlines inside strings, no unescaped `"` inside strings, no trailing commas. Emit shorter `task_prompt` strings if you're unsure — a terse plan that parses beats a rich plan that doesn't.

   **MANDATORY per-agent guard clauses.** Every `task_prompt` MUST embed these four rules verbatim, translated into the same language the agent will reply in (`$LANGUAGE`) — mixed English/non-English prompts bias the child toward writing CSV headers and `_signals/*.md` in English:

   a. **Stay in your lane.** You are exactly ONE agent (`<name>`). Do NOT create sibling-role sub-directories (e.g. `ceo/`, `cfo/`, `marketing/`) inside your output dir. Do NOT write reports as if you were another agent. Only files in your own `output_subdir`.
   b. **Consolidation is not your job.** Do NOT emit `summary.md` / `摘要.md` / `思维导图.md` / `流程图.md` / `mindmap.md` / `flowchart.md` or similar skill-reserved names. The host re-invokes the parent backend for consolidation.
   c. **Language uniformity.** Every file (markdown, CSV headers and non-proper-noun cells, `_signals/<name>.md`, code comments) in `$LANGUAGE`. Proper nouns stay original, numbers stay numeric. Do NOT switch to English for "technical" sections or protocol blocks.
   d. **File extension whitelist.** Only `.md`, `.csv`, `.png`. NO `.py` / `.sh` / `.json` / `.txt` / `.html`. Render charts directly to PNG.

3. Stop. The host will fan out real child processes in parallel and then call you back with every agent's output files ready to consolidate.

**External research**: If the task requires web search / URL fetch, these are available only through MCP servers (Exa, Tavily, Brave, Firecrawl, etc.). Check `~/.codex/config.toml` `[mcp_servers.*]` for what's installed. If none are configured for research and the task needs external information, note this limitation in your final report rather than making up data.

---

TXT;
}
