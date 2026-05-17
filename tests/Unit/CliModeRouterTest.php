<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit;

use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAICore\Modes\CliModeRouter;
use SuperAICore\Modes\CrossLayerDispatcher;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * CliModeRouter extends SDK's ModeRouter with two extras: `cli:*` /
 * `sdk:*` leaf provider tags route through CrossLayerDispatcher
 * instead of being treated as mode names, and the TeamRegistry is
 * consulted for `team:` option values.
 *
 * Pin those behaviours so a future refactor can't silently break
 * either of the two integration points the rest of the cross-mode
 * design depends on.
 */
class CliModeRouterTest extends TestCase
{
    public function test_cli_leaf_tag_routes_through_cross_layer_dispatcher(): void
    {
        $captured = null;
        $cross = new CrossLayerDispatcher(new class($captured) extends Dispatcher {
            public function __construct(private mixed &$captured) {}
            public function dispatch(array $options): ?array
            {
                $this->captured = $options;
                return ['text' => 'leaf-output', 'cost_usd' => 0.07, 'backend' => $options['backend']];
            }
        });
        $router = new CliModeRouter($cross);
        $ctx = ModeContext::root('squad');

        $result = $router->dispatch('cli:claude_cli', 'task', $ctx);

        $this->assertSame('leaf-output', $result->text);
        $this->assertSame(0.07, $result->costUsd);
        $this->assertSame('claude_cli', $captured['backend']);
        // Cost accumulates into the shared ledger under the leaf tag.
        $this->assertEqualsWithDelta(0.07, $ctx->costLedger->total(), 0.0001);
    }

    public function test_sdk_leaf_tag_routes_through_superagent_backend(): void
    {
        $captured = null;
        $cross = new CrossLayerDispatcher(new class($captured) extends Dispatcher {
            public function __construct(private mixed &$captured) {}
            public function dispatch(array $options): ?array
            {
                $this->captured = $options;
                return ['text' => 'sdk-output', 'cost_usd' => 0.05];
            }
        });
        $router = new CliModeRouter($cross);
        $ctx = ModeContext::root('squad');

        $router->dispatch('sdk:anthropic', 'task', $ctx);

        $this->assertSame('superagent', $captured['backend']);
        $this->assertSame('anthropic', $captured['provider_config']['provider']);
    }

    public function test_mode_name_falls_through_to_parent_dispatch(): void
    {
        $cross = new CrossLayerDispatcher(new class extends Dispatcher {
            public function __construct() {}
            public function dispatch(array $options): ?array { return ['text' => '', 'cost_usd' => 0]; }
        });
        $router = new CliModeRouter($cross);

        // Register a stub orchestrator under 'smart'.
        $router->register(new class implements ModeOrchestrator {
            public function modeName(): string { return 'smart'; }
            public function execute(string $task, ModeContext $context, array $options = []): ModeResult
            {
                return new ModeResult(text: 'smart-out', costUsd: 0.0, mode: 'smart', trace: $context->modeStack);
            }
        });

        $result = $router->dispatch('smart', 'task', ModeContext::root('squad'));
        $this->assertSame('smart-out', $result->text);
    }
}
