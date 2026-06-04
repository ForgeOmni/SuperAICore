<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit;

use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAgent\Modes\ModeRouter;
use SuperAgent\Modes\ModeRouterRegistry;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\SquadPlan;
use SuperAgent\Squad\SubTask;
use SuperAICore\Modes\CliSquadOrchestrator;
use SuperAICore\Modes\CrossLayerDispatcher;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * Pin the cross-mode SubTask handling inside `CliSquadOrchestrator`:
 *
 *   - When a SubTask has `mode: smart` AND a `ModeRouter` is
 *     installed via the SDK SPI, the step recurses through the
 *     router instead of running as a plain leaf dispatch.
 *   - When no router is installed, the step silently falls back to
 *     the plain dispatch — loose coupling guarantee.
 *   - The envelope reports `has_cross_mode: true` when any step in
 *     the plan declared cross-mode fields.
 *
 * The orchestrator uses `ModeRouterRegistry::get()` (SDK SPI), not
 * a direct reference to `CliModeRouter` — this test pins that the
 * coupling is exclusively through the SPI.
 */
class CliSquadCrossModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\SuperAgent\Modes\ModeRouterRegistry::class)
            || !class_exists(\SuperAgent\Squad\SquadPlan::class)) {
            $this->markTestSkipped('forgeomni/superagent not installed');
        }
        ModeRouterRegistry::clear();
    }

    protected function tearDown(): void
    {
        if (class_exists(\SuperAgent\Modes\ModeRouterRegistry::class)) {
            ModeRouterRegistry::clear();
        }
        parent::tearDown();
    }

    public function test_envelope_flags_has_cross_mode(): void
    {
        $plan = new SquadPlan(
            name: 't',
            description: null,
            subTasks: [
                new SubTask('plain', 'r', 'p', DifficultyClass::EASY),
                new SubTask('recursive', 'r', 'p', DifficultyClass::HARD, [], false, null, null, null, 'smart'),
            ],
        );
        $orch = $this->buildOrchestrator();

        // No router installed — recursive step falls back to plain
        // dispatch but the envelope still flags that the plan had
        // cross-mode steps.
        $result = $orch->run('task', ['plan' => $plan]);
        $this->assertTrue($result['has_cross_mode']);
    }

    public function test_recursive_step_routes_through_registered_router(): void
    {
        // Capture: did the router actually receive the recursive step?
        $captured = (object) ['mode' => null, 'task' => null];

        $router = new ModeRouter();
        $router->register(new class($captured) implements ModeOrchestrator {
            public function __construct(private object $captured) {}
            public function modeName(): string { return 'smart'; }
            public function execute(string $task, ModeContext $context, array $options = []): ModeResult
            {
                $this->captured->mode = $context->currentMode();
                $this->captured->task = $task;
                return new ModeResult(
                    text: 'recursed-output',
                    costUsd: 0.12,
                    mode: 'smart',
                    trace: $context->modeStack,
                );
            }
        });
        ModeRouterRegistry::set($router);

        $plan = new SquadPlan(
            name: 't',
            description: null,
            subTasks: [
                new SubTask('recursive', 'r', 'do {{task}}', DifficultyClass::HARD, [], false, null, null, null, 'smart'),
            ],
        );

        $orch = $this->buildOrchestrator();
        $orch->run('outer-task', ['plan' => $plan]);

        // The orchestrator built the request's prompt by interpolating
        // {{task}} inside the YAML's prompt template — we verify the
        // router saw a non-empty recursion.
        $this->assertSame('smart', $captured->mode);
        $this->assertNotNull($captured->task);
    }

    public function test_no_router_installed_falls_back_to_plain_dispatch(): void
    {
        ModeRouterRegistry::clear();  // explicit: no router

        $plan = new SquadPlan(
            name: 't',
            description: null,
            subTasks: [
                new SubTask('recursive', 'r', 'p', DifficultyClass::HARD, [], false, null, null, null, 'smart'),
            ],
        );

        $orch = $this->buildOrchestrator();
        // Should NOT throw — the absence of a registered router means
        // graceful degradation to plain dispatch, not error.
        $result = $orch->run('task', ['plan' => $plan]);
        $this->assertIsArray($result);
        $this->assertTrue($result['has_cross_mode']);
    }

    private function buildOrchestrator(): CliSquadOrchestrator
    {
        // Stub backend dispatcher that returns a canned envelope.
        $cross = new CrossLayerDispatcher(new class extends Dispatcher {
            public function __construct() {}
            public function dispatch(array $options): ?array
            {
                return ['text' => 'plain-output', 'cost_usd' => 0.01, 'backend' => $options['backend'] ?? 'fake'];
            }
        });
        return new CliSquadOrchestrator($cross);
    }
}
