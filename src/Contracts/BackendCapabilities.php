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

    // ─── Spawn-Plan protocol (Phase C) ───
    //
    // Three-phase agent-spawn-emulation dance for backends that don't
    // have a native sub-agent primitive (codex, gemini today, future
    // engines tomorrow):
    //
    //   Phase 1 — caller prepends `spawnPreamble()` to the user prompt
    //             (or relies on `transformPrompt()` to do it). The
    //             preamble instructs the model to emit `_spawn_plan.json`
    //             listing the agents it would have spawned, then STOP.
    //   Phase 2 — host detects the plan file and hands it to
    //             {@see AgentSpawn\Orchestrator} to fan out N child CLI
    //             processes in parallel.
    //   Phase 3 — host re-invokes the parent backend with the
    //             `consolidationPrompt()` template, pointing at every
    //             child's output subdir. The model reads them and
    //             produces the final summary/meta files.
    //
    // Backends with native sub-agent support (claude) or that don't fit
    // this protocol (kiro/copilot/superagent) return empty strings.

    /**
     * Phase 1 preamble — instructs the model to write `_spawn_plan.json`
     * in the run's output directory and stop. Returns '' for backends
     * that can spawn sub-agents natively or don't fit the protocol.
     *
     * Implementations that DO use the protocol (codex/gemini) typically
     * return their existing PREAMBLE constant. The `$outputDir` parameter
     * is passed in case a future preamble wants to bake the absolute path
     * into the instructions; current implementations ignore it.
     */
    public function spawnPreamble(string $outputDir): string;

    /**
     * Phase 3 consolidation prompt — re-invocation template that points
     * at every fanned-out agent's output subdir and asks the model for
     * the final summary/meta files. Returns '' for backends that don't
     * use the spawn-plan protocol.
     *
     * Hosts are expected to pass:
     *   - `$plan` — the SpawnPlan loaded in Phase 2 (carries agent list)
     *   - `$report` — Phase 2 fanout report (per-agent exit code, log path,
     *                 duration). Same shape `Orchestrator::run()` returns.
     *   - `$outputDir` — where the consolidation pass should write its
     *                    final files (typically the same dir Phase 1 used).
     *
     * The default consolidation prompt asks for `摘要.md` / `思维导图.md`
     * / `流程图.md` (SuperTeam's convention). Hosts that want a different
     * file set should override per-call by NOT relying on this method
     * and building their own consolidation prompt.
     *
     * @param array<int,array{name:string,exit:int,log:string,duration_ms:int,error:?string}> $report
     */
    public function consolidationPrompt(\SuperAICore\AgentSpawn\SpawnPlan $plan, array $report, string $outputDir): string;
}
