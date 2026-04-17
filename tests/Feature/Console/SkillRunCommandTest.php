<?php

namespace SuperAICore\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use SuperAICore\Console\Commands\SkillRunCommand;
use SuperAICore\Registry\Skill;
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Runner\SkillRunner;
use SuperAICore\Services\CapabilityRegistry;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SkillRunCommandTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = dirname(__DIR__, 2) . '/Fixtures/skills';
    }

    public function test_claude_exec_runs_skill_via_injected_runner(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'alpha',
            'args' => ['hello', 'world'],
            '--exec' => 'claude',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('alpha', $capture['claude']['name']);
        // Args are now rendered into the skill body as an <args> XML block
        // by SkillRunCommand before the runner is invoked, so the runner
        // sees args=[] plus an args block appended to the body.
        $this->assertSame([], $capture['claude']['args']);
        $this->assertStringContainsString('<args>', $capture['claude']['skill']->body);
        $this->assertStringContainsString('hello', $capture['claude']['skill']->body);
        $this->assertStringContainsString('world', $capture['claude']['skill']->body);
        $this->assertFalse($capture['claude']['dry']);
    }

    public function test_unknown_skill_exits_non_zero(): void
    {
        [$tester] = $this->buildTester();

        $exit = $tester->execute(['name' => 'no-such-skill']);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Skill not found', $tester->getDisplay());
    }

    public function test_fallback_exec_walks_chain_to_claude_in_dry_run(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'alpha',
            '--exec' => 'fallback',
            '--fallback-chain' => 'claude',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('running on claude', $tester->getDisplay());
        $this->assertSame('alpha', $capture['claude']['name']);
    }

    public function test_native_exec_gemini_translates_body_and_reports_probe(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'toolheavy',
            '--exec' => 'native',
            '--backend' => 'gemini',
        ]);

        $this->assertSame(0, $exit);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('[probe] incompatible', $display);
        $this->assertStringContainsString('Agent', $display);
        $this->assertStringContainsString('[translate] mapped', $display);

        $sent = $capture['gemini']['skill'] ?? null;
        $this->assertInstanceOf(Skill::class, $sent);
        $this->assertStringContainsString('read_file', $sent->body);
        $this->assertStringContainsString('google_web_search', $sent->body);
    }

    public function test_native_exec_claude_is_compatible_and_untranslated(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'toolheavy',
            '--exec' => 'native',
            '--backend' => 'claude',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('[probe]', $tester->getDisplay());
        $this->assertStringNotContainsString('[translate]', $tester->getDisplay());

        $sent = $capture['claude']['skill'] ?? null;
        $this->assertInstanceOf(Skill::class, $sent);
        $this->assertStringContainsString('Agent', $sent->body);
    }

    public function test_args_schema_rejects_missing_required_positional(): void
    {
        [$tester] = $this->buildTester();

        $exit = $tester->execute(['name' => 'audit']); // schema requires 2, we pass 0

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('missing required argument', $tester->getDisplay());
        $this->assertStringContainsString('target_url', $tester->getDisplay());
    }

    public function test_args_schema_renders_named_arg_tags_into_body(): void
    {
        [$tester, $capture] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'audit',
            'args' => ['https://example.com', 'full'],
            '--exec' => 'claude',
        ]);

        $this->assertSame(0, $exit);
        $body = $capture['claude']['skill']->body;
        $this->assertStringContainsString('<arg name="target_url">https://example.com</arg>', $body);
        $this->assertStringContainsString('<arg name="scope">full</arg>', $body);
    }

    public function test_args_schema_rejects_extra_positional(): void
    {
        [$tester] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'audit',
            'args' => ['a', 'b', 'c', 'd'],
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('extra positional argument', $tester->getDisplay());
    }

    public function test_native_exec_gemini_degraded_on_unmapped_canonical(): void
    {
        [$tester] = $this->buildTester();

        $exit = $tester->execute([
            'name' => 'notebookish',
            '--exec' => 'native',
            '--backend' => 'gemini',
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[probe] degraded', $display);
        $this->assertStringContainsString('TodoWrite', $display);
    }

    /**
     * @return array{0: CommandTester, 1: \ArrayObject<string, array<string,mixed>>}
     */
    private function buildTester(): array
    {
        $registry = new SkillRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        /** @var \ArrayObject<string, array<string,mixed>> $capture */
        $capture = new \ArrayObject();
        $makeRunner = fn(string $key): SkillRunner => new class($capture, $key) implements SkillRunner {
            public function __construct(public \ArrayObject $bag, public string $key) {}
            public function runSkill(Skill $skill, array $args, bool $dryRun): int
            {
                $this->bag[$this->key] = [
                    'name'  => $skill->name,
                    'args'  => $args,
                    'dry'   => $dryRun,
                    'skill' => $skill,
                ];
                return 0;
            }
        };

        $runners = [
            'claude' => $makeRunner('claude'),
            'codex'  => $makeRunner('codex'),
            'gemini' => $makeRunner('gemini'),
        ];

        $command = new SkillRunCommand($registry, new CapabilityRegistry(), $runners);
        $app = new Application();
        $app->add($command);

        return [new CommandTester($app->find('skill:run')), $capture];
    }
}
