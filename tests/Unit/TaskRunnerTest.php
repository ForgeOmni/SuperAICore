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
}
