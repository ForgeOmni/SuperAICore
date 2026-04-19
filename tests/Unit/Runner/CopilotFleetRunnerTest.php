<?php

namespace SuperAICore\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Agent;
use SuperAICore\Runner\CopilotFleetRunner;
use SuperAICore\Sync\CopilotAgentWriter;
use SuperAICore\Sync\Manifest;

final class CopilotFleetRunnerTest extends TestCase
{
    public function test_dry_run_prints_one_command_per_agent(): void
    {
        $tmp = $this->makeSandboxHome();
        $buffer = '';

        $runner = new CopilotFleetRunner(
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
            copilotHome: $tmp,
        );

        $agents = [
            $this->agent('reviewer'),
            $this->agent('planner'),
        ];

        $results = $runner->runFleet('refactor the auth layer', $agents, dryRun: true);

        $this->assertSame([], $results);
        $this->assertStringContainsString('[dry-run] copilot --agent reviewer', $buffer);
        $this->assertStringContainsString('[dry-run] copilot --agent planner', $buffer);
        $this->assertStringContainsString('--output-format=json', $buffer);
        $this->assertStringContainsString('--allow-all-tools', $buffer);
    }

    public function test_dry_run_forwards_model_override_to_every_agent(): void
    {
        $tmp = $this->makeSandboxHome();
        $buffer = '';

        $runner = new CopilotFleetRunner(
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
            copilotHome: $tmp,
        );

        $runner->runFleet('do X', [$this->agent('a'), $this->agent('b')], dryRun: true, model: 'gpt-5.4');

        $this->assertSame(2, substr_count($buffer, '--model gpt-5.4'));
    }

    public function test_empty_agent_list_returns_empty_results(): void
    {
        $runner = new CopilotFleetRunner(
            writer: fn() => null,
            copilotHome: $this->makeSandboxHome(),
        );

        $this->assertSame([], $runner->runFleet('task', [], dryRun: true));
    }

    private function agent(string $name): Agent
    {
        return new Agent(
            name: $name,
            description: "dummy {$name}",
            source: Agent::SOURCE_PROJECT(),
            body: "you are {$name}",
            path: "/tmp/fake/{$name}.md",
        );
    }

    /**
     * Create a throwaway COPILOT_HOME with an empty agents/ dir so
     * CopilotAgentWriter::syncOne() can write without touching the real
     * user's ~/.copilot.
     */
    private function makeSandboxHome(): string
    {
        $home = sys_get_temp_dir() . '/sac-fleet-test-' . bin2hex(random_bytes(3));
        @mkdir($home . '/agents', 0755, true);
        // ensure manifest exists so Writer doesn't complain
        file_put_contents($home . '/agents/.superaicore-manifest.json', '{}');
        return $home;
    }
}
