<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * SuperAgent is an in-process SDK, not a CLI. Tool calls flow through
 * PHP — there's no stream file, no external MCP file, no prompt-level
 * adaptation required. This adapter mostly reports "no, not applicable"
 * so callers can skip backend-specific work.
 */
class SuperAgentCapabilities implements BackendCapabilities
{
    public function key(): string { return AiProvider::BACKEND_SUPERAGENT; }
    public function toolNameMap(): array { return []; }
    public function supportsSubAgents(): bool { return true; }   // SDK has Agent Teams
    public function supportsMcp(): bool { return false; }        // MCPs wired differently inside SDK
    public function streamFormat(): string { return 'ndjson'; }
    public function mcpConfigPath(): ?string { return null; }
    public function transformPrompt(string $prompt): string { return $prompt; }
    public function renderMcpConfig(array $servers): string { return ''; }
}
