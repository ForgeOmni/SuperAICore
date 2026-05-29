<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;

/**
 * Cursor Composer CLI (`cursor-agent`) adapter.
 *
 * Cursor reads the canonical Claude-Code tool vocabulary natively and
 * loads project rules from `.cursor/rules/` + `AGENTS.md`, so skill bodies
 * and prompts pass through with no tool-name translation.
 *
 * MCP servers live in `.cursor/mcp.json` (project) or `~/.cursor/mcp.json`
 * (global) — the same `mcpServers` JSON shape Claude uses — managed via
 * `cursor-agent mcp {list,login,enable,disable}`. We sync the project file.
 *
 * The headless agent has no flat "spawn N sub-agents" primitive (it uses
 * worktrees + plugins instead), so `supportsSubAgents()` is false — the
 * spawn-plan emulation path applies if a host needs fanout.
 */
class CursorCapabilities implements BackendCapabilities
{
    /** Engine key — matches AiProvider::BACKEND_CURSOR. */
    public function key(): string { return 'cursor'; }

    public function toolNameMap(): array { return []; }

    public function supportsSubAgents(): bool { return false; }
    public function supportsMcp(): bool { return true; }
    public function streamFormat(): string { return 'stream-json'; }
    public function mcpConfigPath(): ?string { return '.cursor/mcp.json'; }

    public function transformPrompt(string $prompt): string
    {
        return $prompt;
    }

    /**
     * Merge our owned server keys into `.cursor/mcp.json` (project) or
     * `~/.cursor/mcp.json` (global), preserving every other entry and any
     * top-level fields. Mirrors CopilotCapabilities' conservative policy.
     */
    public function renderMcpConfig(array $servers): string
    {
        $existing = [];
        foreach (['.cursor/mcp.json', self::homeDir() . '/.cursor/mcp.json'] as $candidate) {
            if ($candidate && is_file($candidate) && is_readable($candidate)) {
                $decoded = json_decode((string) @file_get_contents($candidate), true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                    break;
                }
            }
        }
        if (!isset($existing['mcpServers']) || !is_array($existing['mcpServers'])) {
            $existing['mcpServers'] = [];
        }

        foreach ($servers as $s) {
            if (empty($s['key'])) continue;
            $existing['mcpServers'][$s['key']] = array_filter([
                'command' => $s['command'] ?? null,
                'args'    => $s['args'] ?? [],
                'env'     => $s['env'] ?? new \stdClass(),
            ]);
        }

        return json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected static function homeDir(): string
    {
        return rtrim(getenv('HOME') ?: (getenv('USERPROFILE') ?: ''), '/\\');
    }

    // Cursor's headless agent has no spawn-plan workflow; emulation deferred.
    public function spawnPreamble(string $outputDir): string { return ''; }
    public function consolidationPrompt(\SuperAICore\AgentSpawn\SpawnPlan $plan, array $report, string $outputDir): string { return ''; }
}
