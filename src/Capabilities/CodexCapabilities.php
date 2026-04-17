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
     * Codex reads TOML. Render `[mcp_servers.<key>]` blocks.
     */
    public function renderMcpConfig(array $servers): string
    {
        $out = '';
        foreach ($servers as $s) {
            if (empty($s['key']) || empty($s['command'])) continue;
            $out .= "[mcp_servers.{$s['key']}]\n";
            $out .= 'command = ' . self::tomlString($s['command']) . "\n";
            if (!empty($s['args']) && is_array($s['args'])) {
                $out .= 'args = [' . implode(', ', array_map(fn ($a) => self::tomlString((string) $a), $s['args'])) . "]\n";
            }
            if (!empty($s['env']) && is_array($s['env'])) {
                $out .= "[mcp_servers.{$s['key']}.env]\n";
                foreach ($s['env'] as $k => $v) {
                    $out .= "{$k} = " . self::tomlString((string) $v) . "\n";
                }
            }
            $out .= "\n";
        }
        return $out;
    }

    protected static function tomlString(string $value): string
    {
        // Double-quoted TOML string with basic escaping.
        return '"' . addcslashes($value, "\"\\\n\r\t") . '"';
    }

    const PREAMBLE = <<<'TXT'
<!-- codex-preamble-v1 -->
## Runtime: Codex CLI

You are running under OpenAI codex-rs. Tool names follow the standard Read/Write/Edit/Bash set — no translation needed.

**Agent spawning — Spawn Plan protocol**: codex has no native sub-agent tool. Do NOT play all roles yourself sequentially. Instead, when a skill tells you to spawn / assemble / dispatch N agents:

1. Read each agent's role definition from `.claude/agents/<agent-name>.md`.
2. Write `_spawn_plan.json` in the output directory:
   ```json
   {
     "version": 1, "concurrency": 4,
     "agents": [
       { "name": "ceo-bezos", "system_prompt": "...role.md contents...", "task_prompt": "task-specific instructions...", "output_subdir": "ceo-bezos" },
       ...
     ]
   }
   ```
3. Stop. The host will fan out real child processes in parallel and then call you back with every agent's output files ready to consolidate.

**External research**: If the task requires web search / URL fetch, these are available only through MCP servers (Exa, Tavily, Brave, Firecrawl, etc.). Check `~/.codex/config.toml` `[mcp_servers.*]` for what's installed. If none are configured for research and the task needs external information, note this limitation in your final report rather than making up data.

---

TXT;
}
