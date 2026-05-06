<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Runner\TaskRunner;
use SuperAICore\Services\Dispatcher;

/**
 * TaskRunner is a thin wrapper around Dispatcher — these tests verify
 * the wrapping contract (option mapping, envelope construction, file
 * persistence hooks, failure modes) without touching a real backend.
 */
final class TaskRunnerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sac-taskrunner-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($this->tmpDir);
        }
    }

    public function test_empty_prompt_returns_failed_envelope_without_calling_dispatcher(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', '', []);

        $this->assertFalse($envelope->success);
        $this->assertSame(1, $envelope->exitCode);
        $this->assertSame('claude_cli', $envelope->backend);
        $this->assertStringContainsString('empty prompt', $envelope->error ?? '');
    }

    public function test_dispatcher_null_result_yields_failed_envelope(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn(null);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'hello', ['log_file' => '/tmp/x.log']);

        $this->assertFalse($envelope->success);
        $this->assertSame('/tmp/x.log', $envelope->logFile);
        $this->assertNotNull($envelope->error);
    }

    public function test_successful_dispatch_maps_to_envelope(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text'            => 'final assistant text',
            'model'           => 'claude-sonnet-4-5-20241022',
            'backend'         => 'claude_cli',
            'usage'           => ['input_tokens' => 12, 'output_tokens' => 34],
            'cost_usd'        => 0.0123,
            'shadow_cost_usd' => 0.0123,
            'billing_model'   => 'usage',
            'duration_ms'     => 1500,
            'log_file'        => '/tmp/run.log',
            'exit_code'       => 0,
            'usage_log_id'    => 99,
        ]);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'hello', []);

        $this->assertTrue($envelope->success);
        $this->assertSame(0, $envelope->exitCode);
        $this->assertSame('final assistant text', $envelope->output);
        $this->assertSame('final assistant text', $envelope->summary);
        $this->assertSame(['input_tokens' => 12, 'output_tokens' => 34], $envelope->usage);
        $this->assertSame(0.0123, $envelope->costUsd);
        $this->assertSame('usage', $envelope->billingModel);
        $this->assertSame('claude-sonnet-4-5-20241022', $envelope->model);
        $this->assertSame('claude_cli', $envelope->backend);
        $this->assertSame(1500, $envelope->durationMs);
        $this->assertSame('/tmp/run.log', $envelope->logFile);
        $this->assertSame(99, $envelope->usageLogId);
    }

    public function test_empty_text_with_zero_exit_code_is_still_failure(): void
    {
        // Phase A's stream() returns the envelope with text='' when no
        // result event was parseable. TaskRunner treats that as failure
        // even though exit_code=0 — host code shouldn't try to save an
        // empty summary as a successful TaskResult.
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => '', 'usage' => [], 'exit_code' => 0,
        ]);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('kiro_cli', 'prompt', []);

        $this->assertFalse($envelope->success);
        $this->assertSame(0, $envelope->exitCode);
    }

    public function test_prompt_file_is_written_when_specified(): void
    {
        $promptFile = $this->tmpDir . '/prompt.md';
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => 'ok', 'usage' => [], 'exit_code' => 0,
        ]);

        $runner = new TaskRunner($dispatcher);
        $runner->run('claude_cli', 'my prompt body', ['prompt_file' => $promptFile]);

        $this->assertFileExists($promptFile);
        $this->assertSame('my prompt body', file_get_contents($promptFile));
    }

    public function test_summary_file_is_written_when_text_non_empty(): void
    {
        $summaryFile = $this->tmpDir . '/summary.md';
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => '# Summary\n\nDone.', 'usage' => [], 'exit_code' => 0,
        ]);

        $runner = new TaskRunner($dispatcher);
        $runner->run('claude_cli', 'p', ['summary_file' => $summaryFile]);

        $this->assertFileExists($summaryFile);
        $this->assertSame('# Summary\n\nDone.', file_get_contents($summaryFile));
    }

    public function test_summary_file_not_written_when_text_empty(): void
    {
        $summaryFile = $this->tmpDir . '/summary.md';
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => '', 'usage' => [], 'exit_code' => 0,
        ]);

        $runner = new TaskRunner($dispatcher);
        $runner->run('claude_cli', 'p', ['summary_file' => $summaryFile]);

        $this->assertFileDoesNotExist($summaryFile);
    }

    public function test_runner_only_options_stripped_from_dispatcher_payload(): void
    {
        $captured = null;
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$captured) {
                $captured = $opts;
                return ['text' => 'ok', 'usage' => [], 'exit_code' => 0];
            });

        $runner = new TaskRunner($dispatcher);
        $runner->run('claude_cli', 'p', [
            'prompt_file'    => '/tmp/p.md',
            'summary_file'   => '/tmp/s.md',
            'spawn_plan_dir' => '/tmp/out',
            'fallback_profile' => 'coding',
            'log_file'       => '/tmp/run.log',
            'mcp_mode'       => 'empty',
        ]);

        $this->assertSame('claude_cli', $captured['backend']);
        $this->assertSame('p', $captured['prompt']);
        $this->assertTrue($captured['stream']);
        $this->assertSame('/tmp/run.log', $captured['log_file']);
        $this->assertSame('empty', $captured['mcp_mode']);
        $this->assertArrayNotHasKey('prompt_file', $captured);
        $this->assertArrayNotHasKey('summary_file', $captured);
        $this->assertArrayNotHasKey('spawn_plan_dir', $captured);
        $this->assertArrayNotHasKey('fallback_profile', $captured);
    }

    public function test_log_file_falls_back_to_options_when_dispatcher_omits_it(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => 'ok', 'usage' => [], 'exit_code' => 0,
            // no 'log_file' key
        ]);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'p', ['log_file' => '/tmp/from-options.log']);

        $this->assertSame('/tmp/from-options.log', $envelope->logFile);
    }

    public function test_backend_falls_back_to_arg_when_dispatcher_omits_it(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => 'ok', 'usage' => [], 'exit_code' => 0,
            // no 'backend' key
        ]);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('kiro_cli', 'p', []);

        $this->assertSame('kiro_cli', $envelope->backend);
    }

    public function test_spawn_plan_dir_no_op_when_pipeline_absent(): void
    {
        // Backward compatibility: TaskRunner constructed without a
        // Pipeline (legacy callers) silently treats spawn_plan_dir as a
        // no-op rather than throwing. Hosts that haven't migrated to
        // the post-Phase-C container binding still work.
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => 'ok', 'usage' => [], 'exit_code' => 0,
        ]);

        $runner = new TaskRunner($dispatcher);  // no Pipeline arg
        $envelope = $runner->run('codex_cli', 'p', ['spawn_plan_dir' => $this->tmpDir]);

        $this->assertNull($envelope->spawnReport);
        $this->assertTrue($envelope->success);
    }

    public function test_spawn_plan_dir_activates_pipeline_when_present(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => 'first pass output', 'usage' => [], 'exit_code' => 0,
        ]);

        // Stub Pipeline that always returns a consolidated envelope
        $pipeline = $this->createMock(\SuperAICore\AgentSpawn\Pipeline::class);
        $consolidated = new \SuperAICore\Runner\TaskResultEnvelope(
            success: true, exitCode: 0,
            output: 'first + consolidation',
            summary: 'consolidated',
            usage: [],
            spawnReport: [['name' => 'a', 'exit' => 0, 'log' => '/x', 'duration_ms' => 1, 'error' => null]],
        );
        $pipeline->expects($this->once())
            ->method('maybeRun')
            ->willReturn($consolidated);

        $runner = new TaskRunner($dispatcher, $pipeline);
        $envelope = $runner->run('codex_cli', 'p', ['spawn_plan_dir' => $this->tmpDir]);

        $this->assertSame('consolidated', $envelope->summary);
        $this->assertNotNull($envelope->spawnReport);
    }

    public function test_pipeline_null_return_keeps_first_pass_envelope(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => 'first pass', 'usage' => [], 'exit_code' => 0,
        ]);

        $pipeline = $this->createMock(\SuperAICore\AgentSpawn\Pipeline::class);
        $pipeline->method('maybeRun')->willReturn(null);  // no plan / backend opted out

        $runner = new TaskRunner($dispatcher, $pipeline);
        $envelope = $runner->run('codex_cli', 'p', ['spawn_plan_dir' => $this->tmpDir]);

        $this->assertSame('first pass', $envelope->summary);
        $this->assertNull($envelope->spawnReport);
    }

    public function test_pipeline_not_called_when_spawn_plan_dir_omitted(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => 'ok', 'usage' => [], 'exit_code' => 0,
        ]);

        $pipeline = $this->createMock(\SuperAICore\AgentSpawn\Pipeline::class);
        $pipeline->expects($this->never())->method('maybeRun');

        $runner = new TaskRunner($dispatcher, $pipeline);
        $runner->run('codex_cli', 'p', []);  // no spawn_plan_dir
    }

    public function test_pipeline_not_called_when_first_pass_failed(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn([
            'text' => '', 'usage' => [], 'exit_code' => 0,  // empty text → success=false
        ]);

        $pipeline = $this->createMock(\SuperAICore\AgentSpawn\Pipeline::class);
        $pipeline->expects($this->never())->method('maybeRun');

        $runner = new TaskRunner($dispatcher, $pipeline);
        $envelope = $runner->run('codex_cli', 'p', ['spawn_plan_dir' => $this->tmpDir]);

        $this->assertFalse($envelope->success);
    }

    public function test_fallback_chain_hands_off_context_after_limit_failure(): void
    {
        $captured = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$captured) {
                $captured[] = $opts;
                if ($opts['backend'] === 'claude_cli') {
                    return [
                        'text' => 'Claude usage limit reached. Try again later.',
                        'usage' => [],
                        'exit_code' => 1,
                        'backend' => 'claude_cli',
                    ];
                }

                return [
                    'text' => 'continued on codex',
                    'usage' => [],
                    'exit_code' => 0,
                    'backend' => 'codex_cli',
                ];
            });

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'original task', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
        ]);

        $this->assertTrue($envelope->success);
        $this->assertSame('codex_cli', $envelope->backend);
        $this->assertSame('continued on codex', $envelope->summary);
        $this->assertStringContainsString('original task', $captured[1]['prompt']);
        $this->assertStringContainsString('SuperAICore fallback handoff', $captured[1]['prompt']);
        $this->assertStringContainsString('Claude usage limit reached', $captured[1]['prompt']);
        $this->assertCount(2, $envelope->fallbackReport);
        $this->assertTrue($envelope->fallbackReport[0]['retryable']);
        $this->assertSame('codex_cli', $envelope->fallbackReport[0]['next_backend']);
    }

    public function test_fallback_chain_stops_on_non_matching_failure(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturn([
                'text' => 'Invalid prompt: missing required field.',
                'usage' => [],
                'exit_code' => 1,
                'backend' => 'claude_cli',
            ]);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'original task', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
        ]);

        $this->assertFalse($envelope->success);
        $this->assertSame('claude_cli', $envelope->backend);
        $this->assertCount(1, $envelope->fallbackReport);
    }

    public function test_auto_fallback_chain_uses_default_enabled_order(): void
    {
        $seenBackends = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$seenBackends) {
                $seenBackends[] = $opts['backend'];
                return $opts['backend'] === 'claude_cli'
                    ? ['text' => 'rate limit 429', 'usage' => [], 'exit_code' => 1, 'backend' => 'claude_cli']
                    : ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => $opts['backend']];
            });

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'task', ['fallback_chain' => 'auto']);

        $this->assertTrue($envelope->success);
        $this->assertSame(['claude_cli', 'codex_cli'], $seenBackends);
    }

    public function test_fallback_metadata_is_injected_even_without_original_metadata(): void
    {
        $captured = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$captured) {
                $captured[] = $opts;
                return $opts['backend'] === 'claude_cli'
                    ? ['text' => 'rate limit 429', 'usage' => [], 'exit_code' => 1, 'backend' => 'claude_cli']
                    : ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => 'codex_cli'];
            });

        $runner = new TaskRunner($dispatcher);
        $runner->run('claude_cli', 'task', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
        ]);

        $this->assertSame([
            'fallback_active' => true,
            'fallback_chain' => ['claude_cli', 'codex_cli'],
            'fallback_attempt' => 1,
            'fallback_primary_backend' => 'claude_cli',
            'fallback_backend' => 'claude_cli',
            'fallback_chain_index' => 0,
        ], $captured[0]['metadata']);
        $this->assertSame(2, $captured[1]['metadata']['fallback_attempt']);
        $this->assertSame('codex_cli', $captured[1]['metadata']['fallback_backend']);
    }

    public function test_first_success_still_returns_fallback_report_when_chain_active(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturn([
                'text' => 'ok',
                'usage' => [],
                'exit_code' => 0,
                'backend' => 'claude_cli',
                'duration_ms' => 25,
                'usage_log_id' => 123,
                'cost_usd' => 0.01,
                'billing_model' => 'usage',
            ]);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'task', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
        ]);

        $this->assertTrue($envelope->success);
        $this->assertCount(1, $envelope->fallbackReport);
        $this->assertFalse($envelope->fallbackReport[0]['retryable']);
        $this->assertSame(25, $envelope->fallbackReport[0]['duration_ms']);
        $this->assertSame(123, $envelope->fallbackReport[0]['usage_log_id']);
        $this->assertSame(0.01, $envelope->fallbackReport[0]['cost_usd']);
        $this->assertSame('usage', $envelope->fallbackReport[0]['billing_model']);
    }

    public function test_fallback_profile_uses_configured_chain(): void
    {
        $seenBackends = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$seenBackends) {
                $seenBackends[] = $opts['backend'];
                return $opts['backend'] === 'claude_cli'
                    ? ['text' => 'usage limit reached', 'usage' => [], 'exit_code' => 1, 'backend' => 'claude_cli']
                    : ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => $opts['backend']];
            });

        $runner = new class($dispatcher) extends TaskRunner {
            public array $testConfig = [
                'super-ai-core.task_fallback.chains_by_profile' => [
                    'coding' => ['claude_cli', 'codex_cli', 'gemini_cli'],
                ],
            ];

            protected function configValue(string $key): mixed
            {
                return $this->testConfig[$key] ?? null;
            }
        };

        $envelope = $runner->run('claude_cli', 'task', [
            'fallback_profile' => 'coding',
        ]);

        $this->assertTrue($envelope->success);
        $this->assertSame(['claude_cli', 'codex_cli'], $seenBackends);
    }

    public function test_task_type_chain_takes_precedence_over_global_chain(): void
    {
        $seenBackends = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$seenBackends) {
                $seenBackends[] = $opts['backend'];
                return $opts['backend'] === 'claude_cli'
                    ? ['text' => 'quota exceeded', 'usage' => [], 'exit_code' => 1, 'backend' => 'claude_cli']
                    : ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => $opts['backend']];
            });

        $runner = new class($dispatcher) extends TaskRunner {
            public array $testConfig = [
                'super-ai-core.task_fallback.chain' => ['claude_cli', 'gemini_cli'],
                'super-ai-core.task_fallback.chains_by_task_type' => [
                    'tasks.run' => ['claude_cli', 'codex_cli'],
                ],
            ];

            protected function configValue(string $key): mixed
            {
                return $this->testConfig[$key] ?? null;
            }
        };

        $envelope = $runner->run('claude_cli', 'task', [
            'task_type' => 'tasks.run',
        ]);

        $this->assertTrue($envelope->success);
        $this->assertSame(['claude_cli', 'codex_cli'], $seenBackends);
    }

    public function test_max_attempts_stops_chain(): void
    {
        $seenBackends = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$seenBackends) {
                $seenBackends[] = $opts['backend'];
                return ['text' => 'quota exceeded', 'usage' => [], 'exit_code' => 1, 'backend' => $opts['backend']];
            });

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'task', [
            'fallback_chain' => ['claude_cli', 'codex_cli', 'gemini_cli'],
            'fallback_max_attempts' => 1,
        ]);

        $this->assertFalse($envelope->success);
        $this->assertSame(['claude_cli'], $seenBackends);
        $this->assertSame('max_attempts_reached', $envelope->fallbackDecision['events'][1]['reason']);
    }

    public function test_quality_guard_continues_after_too_short_success(): void
    {
        $seenBackends = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$seenBackends) {
                $seenBackends[] = $opts['backend'];
                return $opts['backend'] === 'claude_cli'
                    ? ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => 'claude_cli']
                    : ['text' => 'full final answer', 'usage' => [], 'exit_code' => 0, 'backend' => 'codex_cli'];
            });

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'task', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
            'fallback_success_min_chars' => 5,
        ]);

        $this->assertTrue($envelope->success);
        $this->assertSame('codex_cli', $envelope->backend);
        $this->assertSame('output_too_short', $envelope->fallbackReport[0]['quality_reason']);
        $this->assertSame(['claude_cli', 'codex_cli'], $seenBackends);
    }

    public function test_callbacks_are_invoked_for_attempts_and_fallback(): void
    {
        $events = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(fn(array $opts) => $opts['backend'] === 'claude_cli'
                ? ['text' => 'rate limit', 'usage' => [], 'exit_code' => 1, 'backend' => 'claude_cli']
                : ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => 'codex_cli']);

        $runner = new TaskRunner($dispatcher);
        $runner->run('claude_cli', 'task', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
            'onAttemptStart' => function (array $payload) use (&$events) {
                $events[] = 'start:' . $payload['backend'];
            },
            'onAttemptFinish' => function (array $payload) use (&$events) {
                $events[] = 'finish:' . $payload['backend'];
            },
            'onFallback' => function (array $payload) use (&$events) {
                $events[] = 'fallback:' . $payload['from_backend'] . '->' . $payload['to_backend'];
            },
        ]);

        $this->assertSame([
            'start:claude_cli',
            'finish:claude_cli',
            'fallback:claude_cli->codex_cli',
            'start:codex_cli',
            'finish:codex_cli',
        ], $events);
    }

    public function test_metadata_task_kind_can_select_chain(): void
    {
        $seenBackends = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$seenBackends) {
                $seenBackends[] = $opts['backend'];
                return $opts['backend'] === 'claude_cli'
                    ? ['text' => 'quota exceeded', 'usage' => [], 'exit_code' => 1, 'backend' => 'claude_cli']
                    : ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => $opts['backend']];
            });

        $runner = new class($dispatcher) extends TaskRunner {
            public array $testConfig = [
                'super-ai-core.task_fallback.chains_by_metadata' => [
                    'task_kind' => [
                        'research' => ['claude_cli', 'kimi_cli'],
                    ],
                ],
            ];

            protected function configValue(string $key): mixed
            {
                return $this->testConfig[$key] ?? null;
            }
        };

        $runner->run('claude_cli', 'task', [
            'metadata' => ['task_kind' => 'research'],
        ]);

        $this->assertSame(['claude_cli', 'kimi_cli'], $seenBackends);
    }

    public function test_failure_class_name_can_enable_fallback(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(fn(array $opts) => $opts['backend'] === 'claude_cli'
                ? ['text' => 'too many requests', 'usage' => [], 'exit_code' => 1, 'backend' => 'claude_cli']
                : ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => 'codex_cli']);

        $runner = new TaskRunner($dispatcher);
        $envelope = $runner->run('claude_cli', 'task', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
            'fallback_on' => ['rate_limit'],
        ]);

        $this->assertTrue($envelope->success);
        $this->assertSame('rate_limit', $envelope->fallbackReport[0]['failure_class']);
    }

    public function test_cooldown_skips_backend_on_next_run(): void
    {
        $seenBackends = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$seenBackends) {
                $seenBackends[] = $opts['backend'];
                if ($opts['backend'] === 'limited_cli') {
                    return ['text' => 'quota exceeded', 'usage' => [], 'exit_code' => 1, 'backend' => 'limited_cli'];
                }
                return ['text' => 'ok', 'usage' => [], 'exit_code' => 0, 'backend' => $opts['backend']];
            });

        $runner = new class($dispatcher) extends TaskRunner {
            protected function configValue(string $key): mixed
            {
                return match ($key) {
                    'super-ai-core.task_fallback.cooldown.seconds' => 60,
                    default => null,
                };
            }

            protected function configBool(string $key, bool $default): bool
            {
                return $key === 'super-ai-core.task_fallback.cooldown.enabled' ? true : $default;
            }
        };

        $runner->run('limited_cli', 'task', [
            'fallback_chain' => ['limited_cli', 'codex_cli'],
        ]);
        $second = $runner->run('limited_cli', 'task', [
            'fallback_chain' => ['limited_cli', 'codex_cli'],
        ]);

        $this->assertTrue($second->success);
        $this->assertSame(['limited_cli', 'codex_cli', 'codex_cli'], $seenBackends);
        $this->assertSame('cooldown', $second->fallbackDecision['skipped'][0]['reason']);
    }

    public function test_explain_fallback_chain_reports_runnable_chain(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $runner = new TaskRunner($dispatcher);

        $explain = $runner->explainFallbackChain('claude_cli', [
            'fallback_chain' => ['claude_cli', 'codex_cli'],
            'fallback_max_attempts' => 2,
        ]);

        $this->assertSame('claude_cli', $explain['primary_backend']);
        $this->assertSame(['claude_cli', 'codex_cli'], $explain['chain']);
        $this->assertSame(['claude_cli', 'codex_cli'], $explain['runnable_chain']);
        $this->assertSame('option:fallback_chain', $explain['source']);
        $this->assertSame(2, $explain['max_attempts']);
    }
}
