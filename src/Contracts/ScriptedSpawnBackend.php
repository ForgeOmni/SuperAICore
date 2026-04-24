<?php

namespace SuperAICore\Contracts;

use Symfony\Component\Process\Process;

/**
 * Detached-spawn variant of {@see Backend} — prepares a long-running
 * `Process` that pipes a prompt file through the CLI and writes all
 * output to a log file, ready for the host to nohup/background-spawn
 * and poll asynchronously via the log.
 *
 * Sibling of `StreamingBackend::stream()`, NOT a replacement. Streaming
 * is for callers that block and consume chunks live via callback;
 * scripted spawn is for callers that detach the child and return the
 * request immediately (SuperTeam's task runner, SuperPilot's background
 * jobs).
 *
 * ## Contract
 *
 * Implementations must return a `Symfony\Component\Process\Process` that,
 * when started, runs a wrapper shell/batch script which:
 *   1. Reads `$options['prompt_file']` from stdin → pipes into the CLI
 *   2. Directs combined stdout+stderr to `$options['log_file']`
 *   3. Runs with cwd = `$options['project_root']`
 *   4. Scrubs / applies env from `$options['env']` (merged with the
 *      backend's own scrub set if it has one — e.g. Claude's
 *      `CLAUDE_CODE_*` markers).
 *   5. Honors `$options['timeout']` + `$options['idle_timeout']`.
 *   6. Applies the backend's `BackendCapabilities::transformPrompt()`
 *      to the prompt file in-place before spawn (if the backend's
 *      capabilities emit a non-identity transform — e.g. Gemini's
 *      tool-name rewrite).
 *
 * This contract lets hosts collapse a per-backend `match` statement
 * that composes argv + flags + MCP args + env scrub into a single
 * polymorphic call:
 *
 *     $backend = app(BackendRegistry::class)->resolve($backendKey);
 *     $process = $backend->prepareScriptedProcess([
 *         'prompt_file'  => $promptFile,
 *         'log_file'     => $logFile,
 *         'project_root' => $projectRoot,
 *         'model'        => $model,
 *         'env'          => $env,
 *     ]);
 *     $process->start(...);
 *
 * New CLI engines only need to implement this interface — host code
 * stays unchanged.
 *
 * @phpstan-type ScriptedOptions array{
 *   prompt_file: string,
 *   log_file: string,
 *   project_root: string,
 *   model?: ?string,
 *   env?: array<string,string|false>,
 *   timeout?: int,
 *   idle_timeout?: int,
 *   disable_mcp?: bool,
 *   mcp_mode?: 'inherit'|'empty'|'file',
 *   mcp_config_file?: string,
 *   session_id?: string,
 *   allowed_tools?: string|string[],
 *   permission_mode?: string,
 *   engine_extra_args?: string[],
 *   extra_cli_flags?: string[],
 * }
 */
interface ScriptedSpawnBackend extends Backend
{
    /**
     * Build a configured `Process` that the caller can nohup/detach.
     *
     * @param  array  $options  See the `ScriptedOptions` typedef above.
     *                          Keys `prompt_file`, `log_file`, and
     *                          `project_root` are required; everything
     *                          else is optional with sensible defaults.
     * @throws \InvalidArgumentException  when required keys are missing.
     */
    public function prepareScriptedProcess(array $options): Process;

    /**
     * Blocking one-shot chat turn — backend owns the whole flow:
     *   - argv construction (read-only flags, empty MCP, small model)
     *   - prompt passing (stdin pipe vs argv arg — differs per CLI)
     *   - output parsing (stream-json / plain-text / end-of-run JSON blob)
     *   - ANSI stripping where needed (kiro / copilot)
     *
     * Host simply gives the prompt + an `onChunk` sink; backend streams
     * display-ready text chunks through it as they come in, and returns
     * the accumulated response at the end.
     *
     * @param  string  $prompt        Full prompt (system + history + user;
     *                                host pre-assembles with injection guards).
     * @param  callable  $onChunk     `function(string $chunk): void` — called
     *                                for each newly-emitted display text segment.
     * @param  array  $options        Optional:
     *                                - `cwd: ?string` — process cwd (defaults
     *                                  to current)
     *                                - `model: ?string`
     *                                - `env: array<string,string|false>` —
     *                                  merged with backend's own env knobs
     *                                - `timeout: ?int` (default 0 = no cap;
     *                                  idle_timeout still applies)
     *                                - `idle_timeout: ?int` (default 300)
     *                                - `allowed_tools: string|string[]`
     *                                  (Claude; other CLIs ignore)
     * @return string  The full assistant response after the subprocess exits.
     * @throws \RuntimeException on non-zero exit with empty output.
     */
    public function streamChat(string $prompt, callable $onChunk, array $options = []): string;
}
