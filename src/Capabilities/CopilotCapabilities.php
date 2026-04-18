<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * GitHub Copilot CLI adapter.
 *
 * Copilot reads `.claude/skills/` natively, so skill bodies pass through
 * with no preamble or tool-name translation. Sub-agents use a different
 * file format (`.github/agents/<name>.agent.md`) and require a sync step,
 * but `supportsSubAgents()` is still true because Copilot has a real
 * `--agent <name>` invocation surface (translator lands in MVP-2).
 */
class CopilotCapabilities implements BackendCapabilities
{
    public function key(): string { return AiProvider::BACKEND_COPILOT; }

    public function toolNameMap(): array
    {
        // Copilot consumes Claude-format SKILL.md verbatim; canonical names match.
        return [];
    }

    public function supportsSubAgents(): bool { return true; }
    public function supportsMcp(): bool { return true; }
    public function streamFormat(): string { return 'text'; }
    public function mcpConfigPath(): ?string { return '.copilot/mcp-config.json'; }

    public function transformPrompt(string $prompt): string
    {
        return $prompt;
    }

    /**
     * Copilot's MCP config is JSON with the same `mcpServers` shape Claude uses,
     * living at `$XDG_CONFIG_HOME/copilot/mcp-config.json` (fallback `~/.copilot/`).
     *
     * Merge policy is more conservative than Claude's: we only touch the
     * server keys we own (the ones in `$servers`) and leave every other
     * `mcpServers` entry alone, so:
     *   - Copilot's built-in GitHub MCP entry stays put
     *   - Servers the user added by hand to `mcp-config.json` aren't wiped
     *   - Top-level fields (auth, theme, etc.) are preserved
     *
     * Callers should run this through `McpManager::syncAllBackends()` rather
     * than invoking it directly.
     */
    public function renderMcpConfig(array $servers): string
    {
        $existing = [];
        foreach ([self::xdgPath(), self::homeDir() . '/.copilot/mcp-config.json'] as $candidate) {
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
                'args' => $s['args'] ?? [],
                'env' => $s['env'] ?? new \stdClass(),
            ]);
        }

        return json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function xdgPath(): ?string
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        return $xdg ? rtrim($xdg, '/') . '/copilot/mcp-config.json' : null;
    }

    protected static function homeDir(): string
    {
        return rtrim(getenv('HOME') ?: (getenv('USERPROFILE') ?: ''), '/\\');
    }
}
