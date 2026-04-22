<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\AgentSpawn\Orchestrator;
use SuperAICore\AgentSpawn\Pipeline;
use SuperAICore\AgentSpawn\SpawnPlan;
use SuperAICore\Runner\TaskResultEnvelope;
use SuperAICore\Services\CapabilityRegistry;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\EngineCatalog;

/**
 * Pipeline unit tests — exercise the dispatch routing and decision tree
 * without spawning real CLI children. Phase 2 (`Orchestrator`) is
 * stubbed via the `orchestratorFactory` constructor seam so no
 * subprocesses fire and the suite stays hermetic.
 */
final class SpawnPipelineTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sac-pipeline-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($this->tmpDir);
        }
    }

    public function test_returns_null_when_first_pass_failed(): void
    {
        $pipeline = $this->makePipeline($this->createMock(Dispatcher::class));

        $envelope = $pipeline->maybeRun(
            backend: 'codex_cli',
            outputDir: $this->tmpDir,
            firstPass: TaskResultEnvelope::failed(1, null, 'first pass failed'),
        );

        $this->assertNull($envelope);
    }

    public function test_returns_null_when_no_plan_file_exists(): void
    {
        $pipeline = $this->makePipeline($this->createMock(Dispatcher::class));

        $envelope = $pipeline->maybeRun(
            backend: 'codex_cli',
            outputDir: $this->tmpDir,
            firstPass: $this->okEnvelope('first pass text'),
        );

        $this->assertNull($envelope);
    }

    public function test_returns_null_when_backend_does_not_participate(): void
    {
        // Drop a plan file so the early "no plan" branch doesn't short-
        // circuit; the test verifies the backend-opt-out branch fires.
        file_put_contents($this->tmpDir . '/_spawn_plan.json', json_encode([
            'agents' => [['name' => 'a', 'task_prompt' => 'do x', 'output_subdir' => 'a']],
        ]));

        $pipeline = $this->makePipeline($this->createMock(Dispatcher::class));

        // claude has native sub-agents — Pipeline should bail before
        // running the orchestrator (consolidationPrompt returns '').
        $envelope = $pipeline->maybeRun(
            backend: 'claude_cli',
            outputDir: $this->tmpDir,
            firstPass: $this->okEnvelope('first pass'),
        );

        $this->assertNull($envelope);
    }

    public function test_runs_consolidation_when_plan_present_and_backend_participates(): void
    {
        file_put_contents($this->tmpDir . '/_spawn_plan.json', json_encode([
            'agents' => [
                ['name' => 'a', 'task_prompt' => 'investigate x', 'output_subdir' => 'a'],
                ['name' => 'b', 'task_prompt' => 'investigate y', 'output_subdir' => 'b'],
            ],
        ]));

        // Stub Orchestrator: returns a fanout report without spawning
        // any real CLI processes.
        $orchestratorFactory = function (string $engineKey) {
            return new class extends Orchestrator {
                public function __construct() {} // skip parent ctor — no ChildRunner needed
                public function run(SpawnPlan $plan, string $outputRoot, string $projectRoot, array $env = [], ?string $model = null, ?callable $onAgentStart = null, ?callable $onAgentFinish = null): array
                {
                    $out = [];
                    foreach ($plan->agents as $agent) {
                        $out[] = [
                            'name' => $agent['name'],
                            'exit' => 0,
                            'log' => "{$outputRoot}/{$agent['output_subdir']}/run.log",
                            'duration_ms' => 1000,
                            'error' => null,
                        ];
                    }
                    return $out;
                }
            };
        };

        $captured = null;
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (array $opts) use (&$captured) {
                $captured = $opts;
                return [
                    'text' => 'consolidated answer',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                    'cost_usd' => 0.05,
                    'shadow_cost_usd' => 0.05,
                    'billing_model' => 'usage',
                    'model' => 'codex-default',
                    'backend' => 'codex_cli',
                    'duration_ms' => 800,
                    'exit_code' => 0,
                    'usage_log_id' => 7,
                ];
            });

        $pipeline = $this->makePipeline($dispatcher, $orchestratorFactory);
        $first = $this->okEnvelope('first pass text', costUsd: 0.02);

        $envelope = $pipeline->maybeRun(
            backend: 'codex_cli',
            outputDir: $this->tmpDir,
            firstPass: $first,
            options: ['task_type' => 'tasks.run', 'capability' => 'demo'],
        );

        $this->assertNotNull($envelope);
        $this->assertTrue($envelope->success);
        $this->assertSame(0, $envelope->exitCode);
        $this->assertStringContainsString('consolidated answer', $envelope->summary);
        $this->assertStringContainsString('first pass text', $envelope->output);
        $this->assertStringContainsString('--- consolidation ---', $envelope->output);
        $this->assertSame('codex_cli', $envelope->backend);
        $this->assertNotNull($envelope->spawnReport);
        $this->assertCount(2, $envelope->spawnReport);
        $this->assertSame(7, $envelope->usageLogId);
        // Cost merge: 0.02 (first) + 0.05 (consolidation) = 0.07
        $this->assertEqualsWithDelta(0.07, $envelope->costUsd, 0.0001);
        // Duration merge: 500 (first) + 800 (consolidation) = 1300
        $this->assertSame(1300, $envelope->durationMs);

        // Dispatcher was called with the consolidation prompt + capability suffix
        $this->assertNotNull($captured);
        $this->assertStringContainsString('Consolidation Pass', $captured['prompt']);
        $this->assertStringContainsString('**a**', $captured['prompt']);
        $this->assertStringContainsString('**b**', $captured['prompt']);
        $this->assertSame('demo.consolidate', $captured['capability']);
        $this->assertTrue($captured['stream']);
        $this->assertSame('codex_cli', $captured['backend']);
        $this->assertArrayNotHasKey('spawn_plan_dir', $captured);
        $this->assertArrayNotHasKey('prompt_file', $captured);
    }

    public function test_returns_first_pass_envelope_when_consolidation_dispatch_returns_null(): void
    {
        file_put_contents($this->tmpDir . '/_spawn_plan.json', json_encode([
            'agents' => [['name' => 'a', 'task_prompt' => 'x', 'output_subdir' => 'a']],
        ]));

        $orchestratorFactory = fn (string $key) => new class extends Orchestrator {
            public function __construct() {}
            public function run(SpawnPlan $plan, string $outputRoot, string $projectRoot, array $env = [], ?string $model = null, ?callable $onAgentStart = null, ?callable $onAgentFinish = null): array
            {
                return [['name' => 'a', 'exit' => 0, 'log' => '/x', 'duration_ms' => 1, 'error' => null]];
            }
        };

        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturn(null);  // consolidation fails

        $pipeline = $this->makePipeline($dispatcher, $orchestratorFactory);
        $first = $this->okEnvelope('first pass', usageLogId: 100);

        $envelope = $pipeline->maybeRun('codex_cli', $this->tmpDir, $first);

        $this->assertNotNull($envelope);
        $this->assertTrue($envelope->success);  // first pass was successful
        $this->assertSame('first pass', $envelope->summary);
        $this->assertNotNull($envelope->spawnReport);  // fanout report retained
        $this->assertSame('consolidation pass failed — fanout report retained', $envelope->error);
        $this->assertSame(100, $envelope->usageLogId);
    }

    public function test_engine_key_resolution_accepts_dispatcher_or_engine_name(): void
    {
        $pipeline = $this->makePipeline($this->createMock(Dispatcher::class));
        $reflection = new \ReflectionMethod(Pipeline::class, 'resolveEngineKey');
        $reflection->setAccessible(true);

        $this->assertSame('codex', $reflection->invoke($pipeline, 'codex_cli'));
        $this->assertSame('codex', $reflection->invoke($pipeline, 'codex'));
        $this->assertSame('claude', $reflection->invoke($pipeline, 'claude_cli'));
        $this->assertSame('kiro', $reflection->invoke($pipeline, 'kiro_cli'));
    }

    public function test_plan_file_moved_to_canonical_location(): void
    {
        // Plan written to cwd-style location (parent of outputDir) instead
        // of the canonical outputDir/_spawn_plan.json. Pipeline should
        // detect AND move it.
        $rogueLocation = sys_get_temp_dir() . '/_spawn_plan.json';
        file_put_contents($rogueLocation, json_encode([
            'agents' => [['name' => 'a', 'task_prompt' => 'x', 'output_subdir' => 'a']],
        ]));

        // chdir so locatePlanFile picks up the rogue location.
        $original = getcwd();
        chdir(sys_get_temp_dir());

        try {
            $orchestratorFactory = fn (string $key) => new class extends Orchestrator {
                public function __construct() {}
                public function run(SpawnPlan $plan, string $outputRoot, string $projectRoot, array $env = [], ?string $model = null, ?callable $onAgentStart = null, ?callable $onAgentFinish = null): array
                {
                    return [];
                }
            };

            $dispatcher = $this->createMock(Dispatcher::class);
            $dispatcher->method('dispatch')->willReturn([
                'text' => 'ok', 'usage' => [], 'exit_code' => 0,
            ]);

            $pipeline = $this->makePipeline($dispatcher, $orchestratorFactory);
            $pipeline->maybeRun('codex_cli', $this->tmpDir, $this->okEnvelope('first'));

            $canonical = $this->tmpDir . '/_spawn_plan.json';
            // The rogue file must have been MOVED away (not merely copied) —
            // the canonical location is where Pipeline then consumes it.
            $this->assertFileDoesNotExist($rogueLocation, 'plan should no longer be at the rogue location');
            // After successful consolidation (our mock dispatcher returned
            // text:'ok', exit_code:0), Pipeline deletes the canonical file
            // so the output dir the founder browses doesn't show an internal
            // mechanism file. Retained on failure paths for post-mortem.
            $this->assertFileDoesNotExist($canonical, 'plan should be cleaned up after successful consolidation');
        } finally {
            if ($original !== false) chdir($original);
            @unlink($rogueLocation);  // cleanup if still there
        }
    }

    // ─── helpers ───

    private function makePipeline(Dispatcher $dispatcher, ?\Closure $orchestratorFactory = null): Pipeline
    {
        // EngineCatalog::__construct calls config() when no overrides
        // arg is supplied, which fails outside a booted Laravel app.
        // Pass an empty overrides array to keep the unit test hermetic.
        return new Pipeline(
            caps: new CapabilityRegistry(),
            dispatcher: $dispatcher,
            catalog: new EngineCatalog([]),
            orchestratorFactory: $orchestratorFactory,
        );
    }

    private function okEnvelope(string $text, ?float $costUsd = null, ?int $usageLogId = null): TaskResultEnvelope
    {
        return new TaskResultEnvelope(
            success: true,
            exitCode: 0,
            output: $text,
            summary: $text,
            usage: ['input_tokens' => 10, 'output_tokens' => 5],
            costUsd: $costUsd,
            durationMs: 500,
            usageLogId: $usageLogId,
        );
    }
}
