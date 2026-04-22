<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Runner\TaskResultEnvelope;

final class TaskResultEnvelopeTest extends TestCase
{
    public function test_constructor_assigns_all_properties(): void
    {
        $envelope = new TaskResultEnvelope(
            success:        true,
            exitCode:       0,
            output:         'raw stream content',
            summary:        'final answer',
            usage:          ['input_tokens' => 10, 'output_tokens' => 5],
            costUsd:        0.0234,
            shadowCostUsd:  0.0234,
            billingModel:   'usage',
            model:          'claude-sonnet-4-5',
            backend:        'claude_cli',
            durationMs:     4521,
            logFile:        '/tmp/run.log',
            usageLogId:     42,
            spawnReport:    null,
            error:          null,
        );

        $this->assertTrue($envelope->success);
        $this->assertSame(0, $envelope->exitCode);
        $this->assertSame('raw stream content', $envelope->output);
        $this->assertSame('final answer', $envelope->summary);
        $this->assertSame(['input_tokens' => 10, 'output_tokens' => 5], $envelope->usage);
        $this->assertSame(0.0234, $envelope->costUsd);
        $this->assertSame('usage', $envelope->billingModel);
        $this->assertSame('claude-sonnet-4-5', $envelope->model);
        $this->assertSame('claude_cli', $envelope->backend);
        $this->assertSame(4521, $envelope->durationMs);
        $this->assertSame('/tmp/run.log', $envelope->logFile);
        $this->assertSame(42, $envelope->usageLogId);
    }

    public function test_failed_factory_marks_envelope_unsuccessful(): void
    {
        $envelope = TaskResultEnvelope::failed(
            exitCode: 2,
            logFile: '/tmp/x.log',
            error: 'no provider configured',
            backend: 'kiro_cli',
        );

        $this->assertFalse($envelope->success);
        $this->assertSame(2, $envelope->exitCode);
        $this->assertSame('no provider configured', $envelope->output);
        $this->assertSame('no provider configured', $envelope->summary);
        $this->assertSame([], $envelope->usage);
        $this->assertSame('/tmp/x.log', $envelope->logFile);
        $this->assertSame('no provider configured', $envelope->error);
        $this->assertSame('kiro_cli', $envelope->backend);
        $this->assertNull($envelope->usageLogId);
    }

    public function test_failed_factory_defaults(): void
    {
        $envelope = TaskResultEnvelope::failed();
        $this->assertFalse($envelope->success);
        $this->assertSame(1, $envelope->exitCode);
        $this->assertSame('', $envelope->output);
        $this->assertNull($envelope->error);
    }

    public function test_to_array_round_trips_all_fields(): void
    {
        $envelope = new TaskResultEnvelope(
            success: true, exitCode: 0, output: 'o', summary: 's', usage: ['a' => 1],
            costUsd: 1.5, shadowCostUsd: 1.5, billingModel: 'usage',
            model: 'm', backend: 'b', durationMs: 100,
            logFile: '/log', usageLogId: 7, spawnReport: ['x'], error: null,
        );

        $arr = $envelope->toArray();

        $this->assertSame(true, $arr['success']);
        $this->assertSame('s', $arr['summary']);
        $this->assertSame(1.5, $arr['cost_usd']);
        $this->assertSame('/log', $arr['log_file']);
        $this->assertSame(7, $arr['usage_log_id']);
        $this->assertSame(['x'], $arr['spawn_report']);
        $this->assertArrayHasKey('billing_model', $arr);
        $this->assertArrayHasKey('shadow_cost_usd', $arr);
    }
}
