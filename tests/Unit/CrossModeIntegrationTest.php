<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit;

use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAICore\Modes\CliModeRouter;
use SuperAICore\Modes\CrossLayerDispatcher;
use SuperAICore\Modes\SquadDispatcherBridge;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * End-to-end integration of the cross-mode contract: SDK's
 * `ModeRouterRegistry` SPI receives the host's `CliModeRouter`,
 * SDK code paths that recurse can use either CLI three-mode or
 * leaf provider tags, and the shared ledger sees every leaf
 * dispatch's cost regardless of which layer it landed on.
 */
class CrossModeIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\SuperAgent\Modes\ModeRouterRegistry::class)
            || !class_exists(\SuperAgent\Squad\SquadDispatcherRegistry::class)) {
            $this->markTestSkipped('forgeomni/superagent not installed');
        }
        // Service provider boot may have already installed a router
        // — clear so each test sees a known state.
        \SuperAgent\Modes\ModeRouterRegistry::clear();
        \SuperAgent\Squad\SquadDispatcherRegistry::clear();
    }

    protected function tearDown(): void
    {
        if (class_exists(\SuperAgent\Modes\ModeRouterRegistry::class)) {
            \SuperAgent\Modes\ModeRouterRegistry::clear();
        }
        if (class_exists(\SuperAgent\Squad\SquadDispatcherRegistry::class)) {
            \SuperAgent\Squad\SquadDispatcherRegistry::clear();
        }
        parent::tearDown();
    }

    public function test_bridge_install_publishes_router_via_mode_registry(): void
    {
        $cross = $this->fakeDispatcher();
        $router = new CliModeRouter($cross);
        $bridge = new SquadDispatcherBridge($cross, $router);

        $this->assertNull(\SuperAgent\Modes\ModeRouterRegistry::get());
        $bridge->install();
        $this->assertSame($router, \SuperAgent\Modes\ModeRouterRegistry::get());

        $bridge->uninstall();
        $this->assertNull(\SuperAgent\Modes\ModeRouterRegistry::get());
    }

    public function test_cross_mode_recursion_accumulates_cost_across_layers(): void
    {
        // Simulate: parent squad → child smart → leaf cli:claude_cli
        $cross = new CrossLayerDispatcher(new class extends Dispatcher {
            public function __construct() {}
            public function dispatch(array $options): ?array
            {
                return ['text' => "leaf:{$options['backend']}", 'cost_usd' => 0.10];
            }
        });
        $router = new CliModeRouter($cross);
        // Register a stub smart that recurses through the same router
        // to a leaf cli tag — the integration we want to prove.
        $router->register(new class($router) implements ModeOrchestrator {
            public function __construct(private \SuperAgent\Modes\ModeRouter $router) {}
            public function modeName(): string { return 'smart'; }
            public function execute(string $task, ModeContext $context, array $options = []): ModeResult
            {
                $r = $this->router->descend('cli:claude_cli', 'subtask', $context);
                return new ModeResult(
                    text: 'smart-merged:' . $r->text,
                    costUsd: $r->costUsd,
                    mode: 'smart',
                    trace: $context->modeStack,
                );
            }
        });

        $ctx = ModeContext::root('squad');
        $result = $router->descend('smart', 'task', $ctx);

        $this->assertStringContainsString('leaf:claude_cli', $result->text);
        $this->assertEqualsWithDelta(0.10, $ctx->costLedger->total(), 0.0001);
        // Ledger tags: 'cli:claude_cli' (leaf) — the smart wrapper
        // didn't record extra cost because it just bubbled the leaf's.
        $byMode = $ctx->costLedger->byMode();
        $this->assertArrayHasKey('cli:claude_cli', $byMode);
    }

    private function fakeDispatcher(): CrossLayerDispatcher
    {
        return new CrossLayerDispatcher(new class extends Dispatcher {
            public function __construct() {}
            public function dispatch(array $options): ?array { return ['text' => '', 'cost_usd' => 0]; }
        });
    }
}
