<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit;

use SuperAICore\Modes\CliAutoMode;
use SuperAICore\Modes\CliSmartOrchestrator;
use SuperAICore\Modes\CliSquadOrchestrator;
use SuperAICore\Modes\CrossLayerDispatcher;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * The cross-layer dispatcher is the seam every mode routes through.
 * A bug here would silently break double-layer / cross-layer cooperation.
 * Tests pin the routing grammar (cli:/sdk:/auto/smart/squad) and the
 * tuple shape the squad adapter produces.
 */
class CrossLayerDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // `CliAutoMode` / `CliSmartOrchestrator` / `CliSquadOrchestrator`
        // implement SDK's `ModeOrchestrator` interface — instantiating
        // them without the SDK on the classpath fatals on the missing
        // interface, leaving Orchestra Testbench's error handlers
        // un-restored (risky-test warning).
        if (!interface_exists(\SuperAgent\Modes\ModeOrchestrator::class)) {
            $this->markTestSkipped('forgeomni/superagent not installed');
        }
    }

    public function test_cli_prefix_routes_to_named_backend(): void
    {
        $captured = null;
        $core = $this->fakeDispatcher(function (array $opts) use (&$captured) {
            $captured = $opts;
            return ['text' => 'ok', 'cost_usd' => 0.01, 'backend' => $opts['backend']];
        });

        $cross = new CrossLayerDispatcher($core);
        $r = $cross->dispatch([
            'provider' => 'cli:claude_cli',
            'prompt'   => 'hello',
        ]);

        $this->assertSame('claude_cli', $captured['backend']);
        $this->assertSame('ok', $r['output']);
        $this->assertSame(0.01, $r['cost_usd']);
    }

    public function test_sdk_prefix_routes_through_superagent_backend_with_provider_config(): void
    {
        $captured = null;
        $core = $this->fakeDispatcher(function (array $opts) use (&$captured) {
            $captured = $opts;
            return ['text' => 'ok', 'cost_usd' => 0.02];
        });
        $cross = new CrossLayerDispatcher($core);
        $cross->dispatch([
            'provider' => 'sdk:anthropic',
            'prompt'   => 'hello',
        ]);

        $this->assertSame('superagent', $captured['backend']);
        $this->assertSame('anthropic', $captured['provider_config']['provider']);
    }

    public function test_no_prefix_treated_as_sdk(): void
    {
        $captured = null;
        $core = $this->fakeDispatcher(function (array $opts) use (&$captured) {
            $captured = $opts;
            return ['text' => 'ok', 'cost_usd' => 0.0];
        });
        $cross = new CrossLayerDispatcher($core);
        $cross->dispatch(['provider' => 'anthropic', 'prompt' => 'x']);
        $this->assertSame('superagent', $captured['backend']);
        $this->assertSame('anthropic', $captured['provider_config']['provider']);
    }

    public function test_options_passthrough_forwards_sdk_knobs(): void
    {
        $captured = null;
        $core = $this->fakeDispatcher(function (array $opts) use (&$captured) {
            $captured = $opts;
            return ['text' => 'ok'];
        });
        $cross = new CrossLayerDispatcher($core);
        $cross->dispatch([
            'provider' => 'sdk:anthropic',
            'prompt'   => 'hi',
            'options'  => [
                'reasoning_effort' => 'max',
                'idempotency_key'  => 'abc-123',
                'features'         => ['thinking' => ['enabled' => true]],
            ],
        ]);

        $this->assertSame('max', $captured['reasoning_effort']);
        $this->assertSame('abc-123', $captured['idempotency_key']);
        $this->assertSame(['thinking' => ['enabled' => true]], $captured['features']);
    }

    public function test_auto_recursion_invokes_bound_auto_mode(): void
    {
        $core = $this->fakeDispatcher(fn () => ['text' => 'leaf', 'cost_usd' => 0.05]);
        $cross = new CrossLayerDispatcher($core);

        // Real CliAutoMode walks back into the dispatcher for its leaf
        // step — wire all three modes so the recursion is reachable.
        $auto = new CliAutoMode($cross, null, ['default_cli' => 'cli:claude_cli']);
        $smart = new CliSmartOrchestrator($cross);
        $squad = new CliSquadOrchestrator($cross);
        $cross->setModes($auto, $smart, $squad);

        $r = $cross->dispatch([
            'provider' => 'auto',
            'prompt'   => 'short',
            'options'  => ['mode' => 'single', 'cli' => 'cli:claude_cli'],
        ]);
        $this->assertSame('leaf', $r['output']);
        $this->assertSame(0.05, $r['cost_usd']);
    }

    public function test_dispatch_returns_empty_tuple_on_null_result(): void
    {
        $core = $this->fakeDispatcher(fn () => null);
        $cross = new CrossLayerDispatcher($core);
        $r = $cross->dispatch(['provider' => 'cli:codex_cli', 'prompt' => 'x']);
        $this->assertSame('', $r['output']);
        $this->assertSame(0.0, $r['cost_usd']);
    }

    private function fakeDispatcher(callable $impl): Dispatcher
    {
        return new class($impl) extends Dispatcher {
            public function __construct(private $impl)
            {
                // Skip parent constructor — we never touch BackendRegistry.
            }
            public function dispatch(array $options): ?array
            {
                return ($this->impl)($options);
            }
        };
    }
}
