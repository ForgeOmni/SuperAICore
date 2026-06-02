<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAICore\SmartFlow\FlowEngine;
use SuperAICore\SmartFlow\FlowOptions;
use SuperAICore\SmartFlow\FlowRegistry;
use SuperAICore\SmartFlow\YamlFlowLoader;

/**
 * The YAML authoring path: templating, condition evaluation, strategy
 * compilation, and that every shipped built-in cross-CLI flow rehearses green.
 */
final class YamlFlowLoaderTest extends TestCase
{
    public function test_templating_resolves_args_and_dotted_step_outputs(): void
    {
        $loader = new YamlFlowLoader();
        $ctx = ['args' => ['goal' => 'ship'], 'steps' => ['plan' => ['output' => ['title' => 'P']]]];
        $this->assertSame('do ship', $loader->render('do {{args.goal}}', $ctx));
        $this->assertSame('title=P', $loader->render('title={{steps.plan.output.title}}', $ctx));
        $this->assertSame('missing=', $loader->render('missing={{args.nope}}', $ctx));
    }

    public function test_condition_evaluator_supports_nonempty_equals_contains(): void
    {
        $loader = new YamlFlowLoader();
        $ctx = ['args' => ['a' => 'hello', 'b' => 'hello', 'c' => '']];
        $this->assertTrue($loader->evalCondition('nonempty:{{args.a}}', $ctx));
        $this->assertFalse($loader->evalCondition('nonempty:{{args.c}}', $ctx));
        $this->assertTrue($loader->evalCondition('equals:{{args.a}}|{{args.b}}', $ctx));
        $this->assertTrue($loader->evalCondition('contains:{{args.a}}|ell', $ctx));
        $this->assertFalse($loader->evalCondition('contains:{{args.a}}|zzz', $ctx));
    }

    public function test_compiles_and_runs_a_parallel_then_gate_flow(): void
    {
        $yaml = <<<'YAML'
name: t-inline
description: inline test flow
steps:
  - name: fan
    strategy: parallel
    agents:
      - {role: reviewer, backend: codex_cli, prompt: "review {{args.x}}"}
      - {role: reviewer, backend: gemini_cli, prompt: "review {{args.x}}"}
  - name: accept
    strategy: gate
    check: "nonempty:{{steps.fan.output}}"
    required: true
return: fan
YAML;
        $def = (new YamlFlowLoader())->loadString($yaml);
        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = sys_get_temp_dir() . '/sf_test_' . bin2hex(random_bytes(4));
        $result = (new FlowEngine())->run($def, ['x' => 'change'], $opts);

        $this->assertTrue($result->isSuccessful());
        $this->assertIsArray($result->value);
        $this->assertCount(2, $result->value);
        $this->assertSame(1, $result->ledger['gates']);
    }

    /**
     * @dataProvider builtinFlows
     */
    public function test_builtin_cross_cli_flows_rehearse_green(string $name, array $args): void
    {
        $registry = new FlowRegistry();
        $def = $registry->get($name);
        $this->assertNotNull($def, "flow {$name} should be discoverable");

        $opts = new FlowOptions(rehearse: true);
        $opts->ledgerDir = sys_get_temp_dir() . '/sf_test_' . bin2hex(random_bytes(4));
        $result = (new FlowEngine())->run($def, $args, $opts);

        $this->assertTrue($result->isSuccessful(), "flow {$name} failed: " . (string) $result->error);
        $this->assertSame(0.0, $result->costUsd());
    }

    /** @return array<string, array{0:string,1:array<string,mixed>}> */
    public static function builtinFlows(): array
    {
        return [
            'cross-cli-review' => ['cross-cli-review', ['diff' => 'diff --git a/x b/x']],
            'cross-cli-dev' => ['cross-cli-dev', ['goal' => 'add caching']],
            'cross-cli-council' => ['cross-cli-council', ['question' => 'why?']],
        ];
    }
}
