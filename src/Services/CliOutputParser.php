<?php

namespace SuperAICore\Services;

use SuperAICore\Backends\ClaudeCliBackend;
use SuperAICore\Backends\CodexCliBackend;
use SuperAICore\Backends\CopilotCliBackend;
use SuperAICore\Backends\GeminiCliBackend;

/**
 * Extracts {model, input_tokens, output_tokens, cached_input_tokens, ...}
 * from a completed CLI process's captured stdout. A thin delegator on top
 * of the backend classes' existing parsers — host apps that spawn the CLI
 * themselves (ClaudeRunner etc.) can reuse the exact same extraction logic
 * without constructing a full backend.
 *
 * Call one of the static parsers, then feed the result into
 * {@see UsageRecorder::record()}:
 *
 *   $parsed = CliOutputParser::parseClaude($stdout);
 *   if ($parsed) {
 *       app(UsageRecorder::class)->record([
 *           'task_type'     => 'tasks.run',
 *           'backend'       => 'claude_cli',
 *           'model'         => $parsed['model'] ?? 'unknown',
 *           'input_tokens'  => $parsed['input_tokens'],
 *           'output_tokens' => $parsed['output_tokens'],
 *           'duration_ms'   => $durationMs,
 *       ]);
 *   }
 *
 * Returns null when the output doesn't match the expected envelope —
 * callers should still record a row with tokens=0 in that case so the
 * dashboard sees the execution happened.
 */
class CliOutputParser
{
    /**
     * Parse `claude -p --output-format=json` single-line envelope.
     *
     * @return array{text:string, model:?string, input_tokens:int, output_tokens:int, cache_read_input_tokens:int, cache_creation_input_tokens:int, total_cost_usd:float, stop_reason:?string}|null
     */
    public static function parseClaude(string $output): ?array
    {
        return (new ClaudeCliBackend())->parseJson($output);
    }

    /**
     * Parse `codex exec --full-auto` JSONL stream. Returns the final
     * `turn.completed.usage` + aggregated assistant text.
     */
    public static function parseCodex(string $output): ?array
    {
        return (new CodexCliBackend())->parseJsonl($output);
    }

    /**
     * Parse Copilot CLI's `--json-stream` JSONL. Copilot reports only
     * output_tokens (billing is request-based).
     */
    public static function parseCopilot(string $output): ?array
    {
        return (new CopilotCliBackend())->parseJsonl($output);
    }

    /**
     * Parse Gemini CLI's JSON envelope (`--output-format=json`). Returns
     * null when the envelope doesn't include usage metadata.
     */
    public static function parseGemini(string $output): ?array
    {
        return (new GeminiCliBackend())->parseJson($output);
    }
}
