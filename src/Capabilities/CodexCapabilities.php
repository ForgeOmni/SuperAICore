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

**Agent spawning**: codex has no native sub-agent tool. When a skill asks you to "spawn N agents in parallel with subagent_type = X", you do NOT have that tool. Instead:
1. Read each agent's role definition from `.claude/agents/<name>.md` yourself.
2. Play each role sequentially — produce every output file the skill asks for.
3. Do not skip output files even if a role feels redundant.

**External research**: If the task requires web search / URL fetch, these are available only through MCP servers (Exa, Tavily, Brave, Firecrawl, etc.). Check `~/.codex/config.toml` `[mcp_servers.*]` for what's installed. If none are configured for research and the task needs external information, note this limitation in your final report rather than making up data.

---

TXT;
}
