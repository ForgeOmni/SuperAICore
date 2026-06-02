<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAICore\Contracts\Backend;
use SuperAICore\SmartFlow\FlowDefinition;
use SuperAICore\SmartFlow\FlowEngine;
use SuperAICore\SmartFlow\FlowOptions;
use SuperAICore\SmartFlow\Skip;

/**
 * End-to-end coverage of the SmartFlow engine: rehearsal (zero-cost stubs),
 * the cross-CLI agent runner against a fake backend, structured-output recovery,
 * gates, council voting, parallel/pipeline, budget enforcement, and resume.
 */
final class SmartFlowEngineTest extends TestCase
{
    private function tempLedgerDir(): string
    {
        return sys_get_temp_dir() . '/sf_test_' . bin2hex(random_bytes(4));
    }

    public function test_rehearsal_produces_schema_conforming_stub_at_zero_cost(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['steps'],
            'properties' => ['steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1]],
        ];
        $def = FlowDefinition::make('t', '', function ($flow) use ($schema) {
            return $flow->agent('plan it', ['role' => 'planner', 'backend' => 'claude_cli', 'schema' => $schema]);
        });

        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = (new FlowEngine())->run($def, ['goal' => 'x'], $opts);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0.0, $result->costUsd());
        $this->assertIsArray($result->value);
        $this->assertArrayHasKey('steps', $result->value);
        $this->assertNotEmpty($result->value['steps']);
    }

    public function test_runner_invokes_backend_and_extracts_fenced_json(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['decision'],
            'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'reject']]],
        ];

        // A fake backend that wraps a valid answer in a ```json fence — exercises
        // the StructuredOutputLadder's "submitted" rung.
        $backend = $this->fakeBackend('codex_cli', "Sure!\n```json\n{\"decision\":\"approve\"}\n```\n");
        $engine = new FlowEngine(null, null, fn (string $name) => $name === 'codex_cli' ? $backend : null);

        $def = FlowDefinition::make('t', '', function ($flow) use ($schema) {
            return $flow->agent('review', ['backend' => 'codex_cli', 'schema' => $schema]);
        });

        $opts = new FlowOptions();
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = $engine->run($def, [], $opts);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(['decision' => 'approve'], $result->value);
        $this->assertSame(1, $result->ledger['layers']['submitted'] ?? 0);
    }

    public function test_invalid_structured_output_becomes_skip(): void
    {
        $schema = ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'integer']]];
        $backend = $this->fakeBackend('claude_cli', 'I cannot answer that.');
        $engine = new FlowEngine(null, null, fn () => $backend);

        $def = FlowDefinition::make('t', '', function ($flow) use ($schema) {
            $out = $flow->agent('go', ['backend' => 'claude_cli', 'schema' => $schema]);
            return Skip::isSkip($out) ? 'skipped' : 'value';
        });

        $opts = new FlowOptions();
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = $engine->run($def, [], $opts);

        $this->assertSame('skipped', $result->value);
        $this->assertSame(1, $result->ledger['skips']);
    }

    public function test_missing_backend_yields_skip_for_schema_call(): void
    {
        $engine = new FlowEngine(null, null, fn () => null); // resolver always misses
        $def = FlowDefinition::make('t', '', function ($flow) {
            $out = $flow->agent('go', ['backend' => 'nope', 'schema' => ['type' => 'object']]);
            return Skip::isSkip($out);
        });
        $opts = new FlowOptions();
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = $engine->run($def, [], $opts);
        $this->assertTrue($result->value);
    }

    public function test_gate_required_failure_fails_the_flow(): void
    {
        $def = FlowDefinition::make('t', '', function ($flow) {
            $flow->gate('must', fn () => false, ['required' => true]);
            return 'unreached';
        });
        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = (new FlowEngine())->run($def, [], $opts);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('must', (string) $result->error);
        $this->assertSame(1, $result->ledger['gates']);
    }

    public function test_gate_fallback_relays_a_substitute_value(): void
    {
        $def = FlowDefinition::make('t', '', function ($flow) {
            $g = $flow->gate('check', fn () => false, ['fallback' => fn () => 'recovered']);
            return $g->relayed ? $g->value : 'no-relay';
        });
        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = (new FlowEngine())->run($def, [], $opts);
        $this->assertSame('recovered', $result->value);
    }

    public function test_council_tallies_votes_across_lenses(): void
    {
        // Backend always votes pass → council passes 3/3.
        $backend = $this->fakeBackend('claude_cli', '{"verdict":"pass","reason":"ok"}');
        $engine = new FlowEngine(null, null, fn () => $backend);
        $def = FlowDefinition::make('t', '', function ($flow) {
            return $flow->council('claim', ['a', 'b', 'c']);
        });
        $opts = new FlowOptions();
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = $engine->run($def, [], $opts);

        $this->assertTrue($result->value['passed']);
        $this->assertSame(3, $result->value['pass']);
        $this->assertSame(3, $result->value['total']);
    }

    public function test_parallel_and_pipeline_return_positional_results(): void
    {
        $engine = new FlowEngine(null, null, fn (string $n) => $this->fakeBackend($n, 'echo:' . $n));
        $def = FlowDefinition::make('t', '', function ($flow) {
            $par = $flow->parallel([
                $flow->call('a', ['backend' => 'claude_cli']),
                $flow->call('b', ['backend' => 'codex_cli']),
            ]);
            $pipe = $flow->pipeline(['x', 'y'], fn ($prev, $item) => $flow->call('s:' . $item, ['backend' => 'gemini_cli']));
            return ['par' => $par, 'pipe' => $pipe];
        });
        $opts = new FlowOptions();
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = $engine->run($def, [], $opts);

        $this->assertSame(['echo:claude_cli', 'echo:codex_cli'], $result->value['par']);
        $this->assertSame(['echo:gemini_cli', 'echo:gemini_cli'], $result->value['pipe']);
    }

    public function test_budget_ceiling_stops_the_flow(): void
    {
        // Each call costs $1. The first runs (budget not yet exhausted) and
        // overshoots the $0.50 ceiling; the second is refused before it runs.
        $backend = $this->fakeBackend('claude_cli', 'hi', 1.0);
        $engine = new FlowEngine(null, null, fn () => $backend);
        $def = FlowDefinition::make('t', '', function ($flow) {
            $flow->agent('one', ['backend' => 'claude_cli']);
            $flow->agent('two', ['backend' => 'claude_cli']);
            return 'done';
        });
        $opts = new FlowOptions(budgetUsd: 0.5);
        $opts->ledgerDir = $this->tempLedgerDir();
        $result = $engine->run($def, [], $opts);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Budget', (string) $result->error);
    }

    public function test_resume_replays_unchanged_prefix_across_a_gate(): void
    {
        $dir = $this->tempLedgerDir();
        $def = FlowDefinition::make('resumable', '', function ($flow) {
            $flow->agent('a', ['backend' => 'claude_cli']);
            $flow->gate('mid', fn () => true);
            $flow->agent('b', ['backend' => 'codex_cli']);
            return 'ok';
        });

        $engine = new FlowEngine();
        $o1 = new FlowOptions(rehearse: true);
        $o1->ledgerDir = $dir;
        $r1 = $engine->run($def, ['k' => 'v'], $o1);
        $this->assertSame(0, $r1->ledger['cached_calls']);

        $o2 = new FlowOptions(rehearse: true);
        $o2->ledgerDir = $dir;
        $o2->resumeRunId = $r1->runId;
        $r2 = $engine->run($def, ['k' => 'v'], $o2);

        // Both agent calls (before AND after the gate) replay from cache.
        $this->assertSame(2, $r2->ledger['calls']);
        $this->assertSame(2, $r2->ledger['cached_calls']);
    }

    /**
     * A minimal {@see Backend} stub returning a fixed text envelope.
     */
    private function fakeBackend(string $name, string $text, float $cost = 0.0): Backend
    {
        return new class ($name, $text, $cost) implements Backend {
            public function __construct(private string $n, private string $t, private float $c) {}

            public function name(): string
            {
                return $this->n;
            }

            public function generate(array $options): ?array
            {
                return [
                    'text' => $this->t,
                    'model' => 'stub-model',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                    'cost_usd' => $this->c,
                ];
            }

            public function isAvailable(array $providerConfig = []): bool
            {
                return true;
            }
        };
    }
}
