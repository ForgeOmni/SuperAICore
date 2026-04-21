<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * Kiro CLI (kiro-cli ≥ 2.0) adapter.
 *
 * Kiro ships native subagent orchestration (DAG with parallel execution),
 * so `supportsSubAgents()` is true — orchestrators should prefer Kiro's
 * built-in planner over the SpawnPlan fallback used for codex/gemini.
 *
 * MCP config lives at `.kiro/settings/mcp.json` (workspace) OR
 * `~/.kiro/settings/mcp.json` (global). We write to the global path so a
 * single package sync reaches every workspace the user opens.
 *
 * Kiro's tool vocabulary is lowercase (`read`, `write`, `bash`, `grep`).
 * `--trust-all-tools` sidesteps per-tool confirmation; the tool-name map
 * matters only when an agent JSON declares a restricted tool list.
 */
class KiroCapabilities implements BackendCapabilities
{
    public function key(): string { return AiProvider::BACKEND_KIRO; }

    public function toolNameMap(): array
    {
        // Kiro's built-in tools are lowercase (per its agent JSON schema).
        // Web tools don't have first-class equivalents — users wire them
        // via MCP servers (e.g. mcp-server-fetch).
        return [
            'Read'  => 'read',
            'Write' => 'write',
            'Edit'  => 'write',
            'Grep'  => 'grep',
            'Glob'  => 'fileSearch',
            'Bash'  => 'bash',
        ];
    }

    public function supportsSubAgents(): bool { return true; }
    public function supportsMcp(): bool { return true; }
    public function streamFormat(): string { return 'stream-json'; }
    public function mcpConfigPath(): ?string { return '.kiro/settings/mcp.json'; }

    public function transformPrompt(string $prompt): string
    {
        return $prompt;
    }

    /**
     * Kiro's mcp.json uses the same top-level `mcpServers` shape Claude uses,
     * plus two extensions we preserve when present in the on-disk file:
     *   - `disabled`:    bool, skips the server without removing its config
     *   - `autoApprove`: array of tool names that bypass confirmation
     *   - `disabledTools`: array of tool names to hide from the agent
     *   - remote servers via `url` / `headers` instead of `command` / `args`
     *
     * Non-destructive merge: we only rewrite the server keys we own, so any
     * hand-added entries in `~/.kiro/settings/mcp.json` are left alone.
     */
    public function renderMcpConfig(array $servers): string
    {
        $existing = [];
        $path = self::homeDir() . '/.kiro/settings/mcp.json';
        if (is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) $existing = $decoded;
        }
        if (!isset($existing['mcpServers']) || !is_array($existing['mcpServers'])) {
            $existing['mcpServers'] = [];
        }

        foreach ($servers as $s) {
            if (empty($s['key'])) continue;

            $entry = [];
            if (!empty($s['url'])) {
                $entry['url'] = $s['url'];
                if (!empty($s['headers'])) $entry['headers'] = $s['headers'];
            } else {
                $entry['command'] = $s['command'] ?? null;
                $entry['args']    = $s['args'] ?? [];
            }
            if (!empty($s['env'])) {
                $entry['env'] = $s['env'];
            }
            // Preserve user's existing disabled/autoApprove/disabledTools flags
            // for this key — the sync layer owns the connection config, not
            // the user's permission policy.
            $prior = $existing['mcpServers'][$s['key']] ?? [];
            foreach (['disabled', 'autoApprove', 'disabledTools'] as $k) {
                if (isset($prior[$k])) $entry[$k] = $prior[$k];
            }

            $existing['mcpServers'][$s['key']] = array_filter(
                $entry,
                static fn ($v) => $v !== null && $v !== [] && $v !== ''
            );
        }

        return json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected static function homeDir(): string
    {
        return rtrim(getenv('HOME') ?: (getenv('USERPROFILE') ?: ''), '/\\');
    }
}
