<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAICore\SmartFlow\AgentCall;
use SuperAICore\SmartFlow\Delegation;
use SuperAICore\SmartFlow\FlowDefinition;
use SuperAICore\SmartFlow\FlowEngine;
use SuperAICore\SmartFlow\FlowOptions;
use SuperAICore\SmartFlow\FlowRegistry;
use SuperAICore\SmartFlow\SuperAgentFlowBridge;

/**
 * Federation: a SuperAICore flow delegating a sub-flow to the SuperAgent SDK's
 * cross-model SmartFlow — named mode (superagent self-dispatches) and spec mode
 * (superagent runs SuperAICore's authored structure). Exercised in rehearsal so
 * the whole nested run is deterministic and zero-cost.
 */
final class DelegationTest extends TestCase
{
    private function tempDir(): string
    {
        return sys_get_temp_dir() . '/sf_test_' . bin2hex(random_bytes(4));
    }

    public function test_delegation_is_parsed_from_opts(): void
    {
        $named = Delegation::fromOpts(['delegate' => 'research-trio', 'flow_args' => ['topic' => 'x']]);
        $this->assertNotNull($named);
        $this->assertSame(Delegation::MODE_NAMED, $named->mode);
        $this->assertSame('research-trio', $named->name);
        $this->assertSame(['topic' => 'x'], $named->args);

        $spec = Delegation::fromOpts(['spec' => ['name' => 'inline', 'steps' => []]]);
        $this->assertNotNull($spec);
        $this->assertSame(Delegation::MODE_SPEC, $spec->mode);

        $this->assertNull(Delegation::fromOpts(['backend' => 'claude_cli']));
    }

    public function test_agentcall_round_trips_delegation_through_serialization(): void
    {
        $call = AgentCall::fromOpts('', ['delegate' => 'research-trio', 'flow_args' => ['t' => 1], 'delegate_provider' => 'openai']);
        $this->assertTrue($call->isDelegation());
        $restored = AgentCall::fromArray($call->toArray());
        $this->assertTrue($restored->isDelegation());
        $this->assertSame('research-trio', $restored->delegation->name);
        $this->assertSame('openai', $restored->delegation->provider);
    }

    public function test_bridge_is_available_with_sdk_installed(): void
    {
        // The SDK is a hard dependency, so the bridge must see its SmartFlow.
        $this->assertTrue((new SuperAgentFlowBridge())->available());
    }

    public function test_named_delegation_runs_an_sdk_flow_rehearsed(): void
    {
        $def = FlowDefinition::make('t', '', function ($flow) {
            return $flow->delegate('research-trio', ['flow_args' => ['topic' => 'caching'], 'delegate_provider' => 'openai']);
        });
        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = $this->tempDir();
        $result = (new FlowEngine())->run($def, [], $opts);

        $this->assertTrue($result->isSuccessful(), (string) $result->error);
        $this->assertSame(0.0, $result->costUsd());
        $this->assertSame(1, $result->ledger['layers']['delegated'] ?? 0);
    }

    public function test_spec_delegation_runs_superaicore_authored_structure(): void
    {
        $spec = [
            'name' => 'mini',
            'steps' => [
                ['name' => 'a', 'role' => 'researcher', 'provider' => 'openai', 'prompt' => 'gather {{args.q}}'],
                ['name' => 'b', 'role' => 'writer', 'provider' => 'anthropic', 'prompt' => "sum:\n{{steps.a.output}}"],
            ],
            'return' => 'b',
        ];
        $def = FlowDefinition::make('t', '', function ($flow) use ($spec) {
            return $flow->delegate('', ['spec' => $spec, 'flow_args' => ['q' => 'vectors']]);
        });
        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = $this->tempDir();
        $result = (new FlowEngine())->run($def, [], $opts);

        $this->assertTrue($result->isSuccessful(), (string) $result->error);
        $this->assertSame('delegated', array_key_first($result->ledger['layers']));
    }

    public function test_unknown_named_flow_fails_gracefully(): void
    {
        $def = FlowDefinition::make('t', '', function ($flow) {
            $out = $flow->delegate('no-such-flow-xyz');
            return $out === '' ? 'empty' : 'value';
        });
        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = $this->tempDir();
        $result = (new FlowEngine())->run($def, [], $opts);

        // No schema on the delegate call → failure surfaces as an empty string,
        // and the flow still completes (the bad leg didn't crash the run).
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('empty', $result->value);
    }

    public function test_federated_yaml_flow_rehearses_green(): void
    {
        $registry = new FlowRegistry();
        $def = $registry->get('cross-cli-federated');
        $this->assertNotNull($def);

        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = $this->tempDir();
        $result = (new FlowEngine())->run($def, ['goal' => 'add a cache', 'research_provider' => 'openai'], $opts);

        $this->assertTrue($result->isSuccessful(), (string) $result->error);
        $this->assertSame(1, $result->ledger['layers']['delegated'] ?? 0);
    }
}
