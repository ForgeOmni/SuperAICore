<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;

/**
 * Grok Build CLI (`grok`) adapter.
 *
 * xAI's Grok Build CLI mirrors Claude Code's surface closely (its `--allow`
 * / `--deny` / `--disallowed-tools` / `--system-prompt-override` flags even
 * document the Claude-Code equivalents), so the canonical tool vocabulary
 * passes through untouched and skills/prompts need no translation.
 *
 * Grok has first-class sub-agents (`--agents <JSON>`, `--agent <name>`,
 * `--no-subagents`, the `create-subagent` skill), so `supportsSubAgents()`
 * is true. MCP servers are managed via `grok mcp {add,remove,list,doctor}`
 * rather than a host-writable flat file, so `mcpConfigPath()` is null
 * (capability advertised; sync is a no-op — operators run `grok mcp add`).
 */
class GrokCapabilities implements BackendCapabilities
{
    /** Engine key — matches AiProvider::BACKEND_GROK (the CLI engine). */
    public function key(): string { return 'grok'; }

    public function toolNameMap(): array { return []; }

    public function supportsSubAgents(): bool { return true; }
    public function supportsMcp(): bool { return true; }
    public function streamFormat(): string { return 'stream-json'; }

    // Grok owns its MCP registry behind `grok mcp add`; there's no flat
    // config file for the host to write, so file-based sync is skipped.
    public function mcpConfigPath(): ?string { return null; }

    public function transformPrompt(string $prompt): string
    {
        return $prompt;
    }

    public function renderMcpConfig(array $servers): string
    {
        // No host-writable MCP config file — managed via `grok mcp add`.
        return '';
    }

    // Grok has native sub-agents, so the spawn-plan emulation isn't used.
    public function spawnPreamble(string $outputDir): string { return ''; }
    public function consolidationPrompt(\SuperAICore\AgentSpawn\SpawnPlan $plan, array $report, string $outputDir): string { return ''; }
}
