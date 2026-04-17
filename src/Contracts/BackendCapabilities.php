<?php

namespace SuperAICore\Contracts;

/**
 * Per-backend capability descriptor.
 *
 * Hosts write prompts and skills targeting one canonical tool vocabulary
 * (Claude Code's: `WebSearch`, `WebFetch`, `Agent`, `Read`, `Write`, ...).
 * Each execution backend (claude-cli, codex-cli, gemini-cli, superagent)
 * has a different tool surface, different MCP config format, and different
 * levels of native agent-spawning support.
 *
 * Implementations of this contract encapsulate those differences so host
 * orchestrators (e.g. SuperTeam's ExecuteTask) can stay backend-agnostic.
 *
 * Reserved key values (see `key()`):
 *   - 'claude'      — Anthropic Claude Code CLI
 *   - 'codex'       — OpenAI codex-rs CLI
 *   - 'gemini'      — Google Gemini CLI
 *   - 'superagent'  — SuperAgent PHP SDK (in-process)
 */
interface BackendCapabilities
{
    /** Short backend identifier, matches AiProvider::BACKEND_* values. */
    public function key(): string;

    /**
     * Map canonical (Claude-Code) tool names to this backend's equivalents.
     * Example (gemini): ['WebSearch' => 'google_web_search', 'WebFetch' => 'web_fetch'].
     * Empty array means "tool names match the canonical set".
     *
     * @return array<string,string>
     */
    public function toolNameMap(): array;

    /**
     * Can this backend natively spawn sub-agents with isolated context?
     * - true: Claude Code (has `Agent` tool + `subagent_type`)
     * - false: codex, gemini (no native sub-agent primitive — must be emulated)
     * - false: superagent (SDK orchestrates differently)
     */
    public function supportsSubAgents(): bool;

    /** Can this backend load MCP servers? */
    public function supportsMcp(): bool;

    /**
     * Stream format this backend emits on `-o stream-json` / equivalent.
     * Used by host log viewers and the future agent-spawn emulator.
     * Values: 'stream-json' | 'ndjson' | 'text' | 'none'.
     */
    public function streamFormat(): string;

    /**
     * Where the backend expects its MCP server config to live, relative to
     * the user's home directory. Null means "this backend has no MCP".
     * Examples:
     *   claude → '.claude/settings.json' (mcpServers key)
     *   codex  → '.codex/config.toml'    ([mcp_servers.*] blocks)
     *   gemini → '.gemini/settings.json' (mcpServers key)
     */
    public function mcpConfigPath(): ?string;

    /**
     * Adapt a prompt before handing it to the CLI. Used to prepend
     * tool-name mapping hints, sub-agent emulation instructions, etc.
     * Default implementations typically return $prompt unchanged.
     */
    public function transformPrompt(string $prompt): string;

    /**
     * Render the same list of MCP servers (abstract spec) into this
     * backend's native config shape. Returns the content to be written
     * into the file at `mcpConfigPath()`.
     *
     * Callers should check `supportsMcp()` first — backends that don't
     * support MCP may return an empty string.
     *
     * @param  array  $servers  List of MCP server specs: each entry has
     *                          ['key' => ..., 'command' => ..., 'args' => [],
     *                           'env' => [...], 'transport' => 'stdio'|'sse']
     */
    public function renderMcpConfig(array $servers): string;
}
