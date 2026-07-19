<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * Claude Code CLI is the canonical tool vocabulary — everything else
 * adapts TO it. No prompt transformation needed.
 */
class ClaudeCapabilities implements BackendCapabilities
{
    public function key(): string { return AiProvider::BACKEND_CLAUDE; }
    public function toolNameMap(): array { return []; }
    public function supportsSubAgents(): bool { return true; }
    public function supportsMcp(): bool { return true; }
    public function streamFormat(): string { return 'stream-json'; }

    /**
     * User-scope MCP servers live in `~/.claude.json` (the file
     * `claude mcp add -s user` writes, top-level `mcpServers` key).
     * Claude Code does NOT read `mcpServers` from `~/.claude/settings.json`
     * — the settings schema has no such key (verified against claude
     * 2.1.215 / code.claude.com settings docs), so the previous
     * settings.json target was a silent no-op. Project scope is `.mcp.json`
     * at the repo root, and per-spawn injection uses `--mcp-config` — both
     * separate surfaces from this user-scope sync.
     */
    public function mcpConfigPath(): ?string { return '.claude.json'; }

    public function transformPrompt(string $prompt): string
    {
        return $prompt;
    }

    public function renderMcpConfig(array $servers): string
    {
        // ~/.claude.json is Claude Code's main user state file (projects
        // history, onboarding flags, OAuth account, ...) — merge and touch
        // ONLY the mcpServers key, preserving everything else verbatim.
        $existing = [];
        $path = (getenv('HOME') ?: '') . '/.claude.json';
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

    // Claude has a native Agent tool — no spawn-plan emulation needed.
    public function spawnPreamble(string $outputDir): string { return ''; }
    public function consolidationPrompt(\SuperAICore\AgentSpawn\SpawnPlan $plan, array $report, string $outputDir): string { return ''; }
}
