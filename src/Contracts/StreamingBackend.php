<?php

namespace SuperAICore\Contracts;

/**
 * Long-running execution variant of {@see Backend} — stream chunks live
 * via an `onChunk` callback while tee'ing them to a log file, then return
 * the same result envelope `generate()` produces.
 *
 * Sibling of `Backend::generate()`, NOT a replacement. Short calls
 * (test_connection, vision routing, embeddings) keep using `generate()`
 * for its lower overhead. Long-running task execution — anything where
 * a host wants a tail-able log + Process Monitor row + sub-second UI
 * preview updates — calls `stream()` instead.
 *
 * Backends opt in by implementing this interface; `Dispatcher::dispatch()`
 * with `['stream' => true, ...]` prefers `stream()` when the resolved
 * backend implements it, falling back to `generate()` otherwise.
 *
 * Implementations should:
 *   - Open a tee log via `Support\TeeLogger` (path comes from
 *     `$options['log_file']`; auto-name via `ProcessRegistrar::defaultLogPath()`
 *     when absent).
 *   - Register the subprocess with `Support\ProcessRegistrar::start()`
 *     so the Process Monitor UI sees a live row.
 *   - Honor `mcp_mode` (relevant for backends that load MCP servers).
 *   - Honor `timeout` / `idle_timeout` overrides — never bake hardcoded
 *     limits into the stream() path.
 *   - Invoke `onChunk($buffer, $type)` for every Symfony Process callback
 *     chunk. `$type` is `Process::OUT` or `Process::ERR`.
 *   - Parse the captured output the same way `generate()` does at the
 *     end, and return the canonical envelope shape.
 *
 * The {@see Backends\Concerns\StreamableProcess} trait packages the
 * register-tee-wait-end dance so each backend's stream() body can stay
 * focused on command construction + output parsing.
 *
 * @phpstan-type StreamOptions array{
 *   prompt?: string,
 *   messages?: array<int,array{role:string,content:mixed}>,
 *   system?: string,
 *   model?: string,
 *   max_tokens?: int,
 *   provider_config?: array<string,mixed>,
 *   log_file?: string,
 *   timeout?: int,
 *   idle_timeout?: int,
 *   mcp_mode?: 'inherit'|'empty'|'file',
 *   mcp_config_file?: string,
 *   external_label?: string,
 *   onChunk?: callable(string $chunk, string $stream): void,
 *   metadata?: array<string,mixed>,
 * }
 */
interface StreamingBackend extends Backend
{
    /**
     * Spawn the backend's CLI in streaming mode.
     *
     * @param array $options  See `StreamOptions` typedef above.
     * @return array|null  Same shape as `Backend::generate()` plus:
     *   - `log_file: string`     where the tee log was written
     *   - `duration_ms: int`     wall-clock subprocess duration
     *   - `exit_code: int`       subprocess exit code (0 = success)
     *
     *   Returns null when the subprocess failed and produced no parsable
     *   output. Callers should check for null before consuming the
     *   envelope; the log file may still exist on disk for diagnostics.
     */
    public function stream(array $options): ?array;
}
