<?php

namespace SuperAICore\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Skill;
use SuperAICore\Runner\FallbackChain;
use SuperAICore\Runner\SkillRunner;
use SuperAICore\Services\CapabilityRegistry;
use Symfony\Component\Console\Output\BufferedOutput;

final class FallbackChainTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/fallback-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function test_single_hop_compatible_backend_runs_and_returns_zero(): void
    {
        $out = new BufferedOutput();
        $skill = $this->plainSkill();

        $fc = new FallbackChain(
            new CapabilityRegistry(),
            $this->factory([
                'claude' => $this->stubRunner(exit: 0, touch: false),
            ]),
            $this->tmp,
        );

        $exit = $fc->run($skill, ['claude'], '', false, $out);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('running on claude', $out->fetch());
    }

    public function test_incompatible_first_hop_is_skipped_to_next(): void
    {
        $out = new BufferedOutput();
        $agentSkill = new Skill(
            name: 'agentic',
            description: null,
            source: Skill::SOURCE_PROJECT(),
            body: 'Call Agent to spawn children and Read files.',
            path: '/tmp/fake/SKILL.md',
        );

        $ranClaude = false;
        $fc = new FallbackChain(
            new CapabilityRegistry(),
            $this->factory([
                'gemini' => $this->failFastRunner('gemini should not run when probe says incompatible'),
                'claude' => $this->stubRunner(exit: 0, touch: false, onRun: function () use (&$ranClaude) { $ranClaude = true; }),
            ]),
            $this->tmp,
        );

        $exit = $fc->run($agentSkill, ['gemini', 'claude'], '', false, $out);

        $this->assertSame(0, $exit);
        $this->assertTrue($ranClaude);
        $display = $out->fetch();
        $this->assertStringContainsString('[fallback] gemini: incompatible', $display);
        $this->assertStringContainsString('running on claude', $display);
    }

    public function test_side_effect_locks_chain_on_first_hop(): void
    {
        $out = new BufferedOutput();
        $skill = $this->plainSkill();

        $claudeRan = false;
        $fc = new FallbackChain(
            new CapabilityRegistry(),
            $this->factory([
                'gemini' => $this->stubRunner(exit: 2, touch: true, tmp: $this->tmp),
                'claude' => $this->stubRunner(exit: 0, touch: false, onRun: function () use (&$claudeRan) { $claudeRan = true; }),
            ]),
            $this->tmp,
        );

        $exit = $fc->run($skill, ['gemini', 'claude'], '', false, $out);

        $this->assertSame(2, $exit, 'locked exit should propagate');
        $this->assertFalse($claudeRan, 'claude must not run after gemini produced side-effect');
        $this->assertStringContainsString('locked on gemini', $out->fetch());
    }

    public function test_failure_without_side_effect_falls_through(): void
    {
        $out = new BufferedOutput();
        $skill = $this->plainSkill();

        $claudeRan = false;
        $fc = new FallbackChain(
            new CapabilityRegistry(),
            $this->factory([
                'gemini' => $this->stubRunner(exit: 7, touch: false),
                'claude' => $this->stubRunner(exit: 0, touch: false, onRun: function () use (&$claudeRan) { $claudeRan = true; }),
            ]),
            $this->tmp,
        );

        $exit = $fc->run($skill, ['gemini', 'claude'], '', false, $out);

        $this->assertSame(0, $exit);
        $this->assertTrue($claudeRan);
        $this->assertStringContainsString('gemini failed (exit 7)', $out->fetch());
    }

    public function test_all_hops_fail_returns_last_exit_code(): void
    {
        $out = new BufferedOutput();
        $skill = $this->plainSkill();

        $fc = new FallbackChain(
            new CapabilityRegistry(),
            $this->factory([
                'gemini' => $this->stubRunner(exit: 7, touch: false),
                'claude' => $this->stubRunner(exit: 9, touch: false),
            ]),
            $this->tmp,
        );

        $exit = $fc->run($skill, ['gemini', 'claude'], '', false, $out);

        $this->assertSame(9, $exit);
    }

    public function test_empty_chain_returns_failure(): void
    {
        $out = new BufferedOutput();
        $fc = new FallbackChain(new CapabilityRegistry(), fn() => null, $this->tmp);

        $exit = $fc->run($this->plainSkill(), [], '', false, $out);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('empty chain', $out->fetch());
    }

    /** @param array<string,SkillRunner> $runners */
    private function factory(array $runners): \Closure
    {
        return fn(string $backend, \Closure $writer): ?SkillRunner => $runners[$backend] ?? null;
    }

    private function stubRunner(int $exit, bool $touch, ?string $tmp = null, ?\Closure $onRun = null): SkillRunner
    {
        $tmp ??= $this->tmp;
        return new class($exit, $touch, $tmp, $onRun) implements SkillRunner {
            public function __construct(
                public int $exit,
                public bool $touch,
                public string $tmp,
                public ?\Closure $onRun,
            ) {}
            public function runSkill(Skill $skill, array $args, bool $dryRun): int
            {
                if ($this->onRun) {
                    ($this->onRun)();
                }
                if ($this->touch) {
                    file_put_contents($this->tmp . '/written-' . bin2hex(random_bytes(3)) . '.txt', 'x');
                }
                return $this->exit;
            }
        };
    }

    private function failFastRunner(string $why): SkillRunner
    {
        return new class($why) implements SkillRunner {
            public function __construct(public string $why) {}
            public function runSkill(Skill $skill, array $args, bool $dryRun): int
            {
                throw new \RuntimeException($this->why);
            }
        };
    }

    private function plainSkill(): Skill
    {
        return new Skill(
            name: 'plain',
            description: null,
            source: Skill::SOURCE_PROJECT(),
            body: "Read the config and report back.\n",
            path: '/tmp/fake/SKILL.md',
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
