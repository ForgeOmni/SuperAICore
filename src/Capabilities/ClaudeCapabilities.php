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
    public function mcpConfigPath(): ?string { return '.claude/settings.json'; }

    public function transformPrompt(string $prompt): string
    {
        return $prompt;
    }

    public function renderMcpConfig(array $servers): string
    {
        // Merge into existing settings.json (apiKeyHelper, hooks, theme, ...).
        $existing = [];
        $path = (getenv('HOME') ?: '') . '/.claude/settings.json';
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
}
