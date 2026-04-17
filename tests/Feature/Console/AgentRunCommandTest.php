<?php

namespace SuperAICore\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use SuperAICore\Console\Commands\AgentRunCommand;
use SuperAICore\Registry\Agent;
use SuperAICore\Registry\AgentRegistry;
use SuperAICore\Runner\AgentRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AgentRunCommandTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = dirname(__DIR__, 2) . '/Fixtures/agents';
    }

    public function test_runs_project_agent_on_inferred_claude_backend(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'security-reviewer',
            'task' => 'audit this diff',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('security-reviewer', $capture['claude']['agent']);
        $this->assertSame('audit this diff', $capture['claude']['task']);
        $this->assertFalse($capture['claude']['dry']);
    }

    public function test_gemini_model_infers_gemini_backend(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'geminer',
            'task' => 'summarize this',
        ]);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('gemini', (array) $capture);
        $this->assertArrayNotHasKey('claude', (array) $capture);
    }

    public function test_backend_flag_overrides_inferred_backend(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'geminer',
            'task' => 'ignore my model',
            '--backend' => 'claude',
        ]);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('claude', (array) $capture);
        $this->assertArrayNotHasKey('gemini', (array) $capture);
    }

    public function test_unknown_agent_exits_non_zero(): void
    {
        [$tester] = $this->buildTester();

        $exit = $tester->execute(['name' => 'no-such-agent', 'task' => 'x']);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Agent not found', $tester->getDisplay());
    }

    public function test_dry_run_is_propagated(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'security-reviewer',
            'task' => 'x',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($capture['claude']['dry']);
    }

    /**
     * @return array{0: CommandTester, 1: \ArrayObject<string, array<string,mixed>>}
     */
    private function buildTester(): array
    {
        $registry = new AgentRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        $capture = new \ArrayObject();
        $make = fn(string $key): AgentRunner => new class($capture, $key) implements AgentRunner {
            public function __construct(public \ArrayObject $bag, public string $key) {}
            public function runAgent(Agent $agent, string $task, bool $dryRun): int
            {
                $this->bag[$this->key] = [
                    'agent' => $agent->name,
                    'task'  => $task,
                    'dry'   => $dryRun,
                    'model' => $agent->model,
                ];
                return 0;
            }
        };

        $runners = [
            'claude' => $make('claude'),
            'codex'  => $make('codex'),
            'gemini' => $make('gemini'),
        ];

        $command = new AgentRunCommand($registry, $runners);
        $app = new Application();
        $app->add($command);

        return [new CommandTester($app->find('agent:run')), $capture];
    }
}
