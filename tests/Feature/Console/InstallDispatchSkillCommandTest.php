<?php

namespace SuperAICore\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use SuperAICore\Console\Commands\InstallDispatchSkillCommand;
use SuperAICore\Services\SkillManager;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `skill:install-dispatch` under a fake $HOME — six-agent coverage,
 * `--agent all`, and the `--uninstall` reverse path (1.1.2).
 */
final class InstallDispatchSkillCommandTest extends TestCase
{
    private ?string $originalHome = null;
    private string $fakeHome = '';

    protected function setUp(): void
    {
        $this->originalHome = getenv('HOME') ?: null;
        $this->fakeHome = sys_get_temp_dir() . '/sac-install-dispatch-' . bin2hex(random_bytes(4));
        mkdir($this->fakeHome, 0755, true);
        putenv('HOME=' . $this->fakeHome);
    }

    protected function tearDown(): void
    {
        putenv($this->originalHome !== null ? 'HOME=' . $this->originalHome : 'HOME');
        $this->rmrf($this->fakeHome);
    }

    private function tester(): CommandTester
    {
        $app = new Application();
        $app->add(new InstallDispatchSkillCommand());
        return new CommandTester($app->find('skill:install-dispatch'));
    }

    public function test_default_installs_into_claude_and_codex(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([]));

        foreach (['.claude/skills', '.codex/skills'] as $dir) {
            $skill = $this->fakeHome . '/' . $dir . '/superaicore-dispatch/SKILL.md';
            $this->assertFileExists($skill, "missing {$skill}");
        }
        $this->assertDirectoryDoesNotExist($this->fakeHome . '/.gemini/skills/superaicore-dispatch');
    }

    public function test_agent_all_covers_every_known_skill_dir(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--agent' => ['all']]));

        foreach (SkillManager::knownBackends() as $backend) {
            $dir = SkillManager::targetDirFor($backend);
            $this->assertFileExists($dir . '/superaicore-dispatch/SKILL.md', "missing for {$backend}");
        }
        // Cursor uses its own `skills-cursor` layout.
        $this->assertStringContainsString('skills-cursor', (string) SkillManager::targetDirFor('cursor'));
    }

    public function test_uninstall_removes_only_the_dispatch_skill(): void
    {
        // A user-authored skill sitting next to ours must survive.
        $userSkill = $this->fakeHome . '/.claude/skills/my-own-skill';
        mkdir($userSkill, 0755, true);
        file_put_contents($userSkill . '/SKILL.md', 'MINE');

        $this->tester()->execute(['--agent' => ['claude']]);
        $this->assertFileExists($this->fakeHome . '/.claude/skills/superaicore-dispatch/SKILL.md');

        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--agent' => ['claude'], '--uninstall' => true]));
        $this->assertStringContainsString('removed=1', $tester->getDisplay());

        $installed = $this->fakeHome . '/.claude/skills/superaicore-dispatch';
        $this->assertFalse(is_link($installed) || file_exists($installed));
        $this->assertSame('MINE', file_get_contents($userSkill . '/SKILL.md'));
    }

    public function test_uninstall_when_nothing_installed_reports_zero(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--agent' => ['grok'], '--uninstall' => true]));
        $this->assertStringContainsString('removed=0', $tester->getDisplay());
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach ((array) scandir($path) as $e) {
            if ($e === '.' || $e === '..') continue;
            $this->rmrf($path . '/' . $e);
        }
        @rmdir($path);
    }
}
