<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\ClaudeCliBackend;
use SuperAICore\Backends\CodexCliBackend;

/**
 * Session-continuation plumbing added by the ai-dispatch parity wave:
 * envelopes must surface the engine's session/thread id, and the codex
 * argv must switch to `exec resume <id>` when a resume id is supplied.
 */
final class DispatchResumeBackendTest extends TestCase
{
    public function test_codex_parse_jsonl_captures_thread_id(): void
    {
        $backend = new CodexCliBackend();
        $parsed = $backend->parseJsonl(implode("\n", [
            '{"type":"thread.started","thread_id":"th_abc123"}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"hi"}}',
            '{"type":"turn.completed","usage":{"input_tokens":1,"output_tokens":2,"cached_input_tokens":0}}',
        ]));

        $this->assertSame('th_abc123', $parsed['thread_id']);
        $this->assertSame('hi', $parsed['text']);
    }

    public function test_codex_parse_jsonl_without_thread_event_yields_null_thread(): void
    {
        $backend = new CodexCliBackend();
        $parsed = $backend->parseJsonl('{"type":"turn.completed","usage":{"input_tokens":1,"output_tokens":2}}');
        $this->assertNull($parsed['thread_id']);
    }

    public function test_codex_exec_command_switches_to_resume_subcommand(): void
    {
        $backend = new class extends CodexCliBackend {
            public function buildCmd(array $options, ?string $model): array
            {
                return $this->execCommand($options, $model);
            }
        };

        $fresh = $backend->buildCmd([], 'gpt-5.3-codex');
        $this->assertSame(['codex', 'exec', '-', '--json', '--full-auto', '--skip-git-repo-check', '--model', 'gpt-5.3-codex'], $fresh);

        $resume = $backend->buildCmd(['resume_session_id' => 'th_abc123'], null);
        $this->assertSame(['codex', 'exec', 'resume', 'th_abc123', '-', '--json', '--full-auto', '--skip-git-repo-check'], $resume);
    }

    public function test_claude_parse_json_captures_session_id(): void
    {
        $backend = new ClaudeCliBackend();
        $parsed = $backend->parseJson(json_encode([
            'type' => 'result',
            'result' => 'DONE',
            'session_id' => '011ad69e-25cf-4f50-b39c-cc931f257a5b',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
            'modelUsage' => ['claude-haiku-4-5-20251001' => ['costUSD' => 0.01]],
        ]));

        $this->assertSame('011ad69e-25cf-4f50-b39c-cc931f257a5b', $parsed['session_id']);
    }

    public function test_claude_parse_stream_json_still_captures_session_id(): void
    {
        $backend = new ClaudeCliBackend();
        $parsed = $backend->parseStreamJson(
            '{"type":"result","result":"ok","session_id":"sess-9","usage":{"input_tokens":1,"output_tokens":1}}'
        );
        $this->assertSame('sess-9', $parsed['session_id']);
    }
}
