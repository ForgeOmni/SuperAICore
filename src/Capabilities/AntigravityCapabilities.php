<?php

namespace SuperAICore\Capabilities;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * Antigravity CLI (`agy`) adapter.
 *
 * agy is an agent harness in its own right (built-in agents surface via
 * `agy agents`, subagent status in the TUI), so — like Claude / Grok /
 * Kiro — we let it drive its own orchestration: `spawnPreamble()` and
 * `consolidationPrompt()` return `''` and the AgentSpawn pipeline
 * fast-exits.
 *
 * Tool vocabulary: agy's print mode emits plain text (no structured
 * tool-call wire we translate against), and prompts written in Claude
 * conventions execute fine — no name mapping needed.
 *
 * MCP: extension management goes through `agy plugin
 * {install,uninstall,list,enable,disable}` rather than a host-writable
 * flat config file, so `mcpConfigPath()` is null and file-based sync is a
 * documented no-op (operators use `agy plugin install`). `supportsMcp()`
 * stays false until a writable config surface is verified.
 */
class AntigravityCapabilities implements BackendCapabilities
{
    /** Engine key — matches AiProvider::BACKEND_ANTIGRAVITY. */
    public function key(): string { return AiProvider::BACKEND_ANTIGRAVITY; }

    public function toolNameMap(): array { return []; }

    public function supportsSubAgents(): bool { return true; }
    public function supportsMcp(): bool { return false; }

    /** Print mode is plain text — no NDJSON stream to parse. */
    public function streamFormat(): string { return 'text'; }

    public function mcpConfigPath(): ?string { return null; }

    public function transformPrompt(string $prompt): string
    {
        return $prompt;
    }

    public function renderMcpConfig(array $servers): string
    {
        // No host-writable MCP config file — managed via `agy plugin`.
        return '';
    }

    // agy orchestrates natively — no spawn-plan emulation.
    public function spawnPreamble(string $outputDir): string { return ''; }
    public function consolidationPrompt(\SuperAICore\AgentSpawn\SpawnPlan $plan, array $report, string $outputDir): string { return ''; }
}
