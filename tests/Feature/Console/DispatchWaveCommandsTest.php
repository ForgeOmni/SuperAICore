<?php

namespace SuperAICore\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use SuperAICore\Console\Commands\AliasesCommand;
use SuperAICore\Console\Commands\PreferencesCommand;
use SuperAICore\Console\Commands\ResumeCommand;
use SuperAICore\Console\Commands\RunsCommand;
use SuperAICore\Services\RunStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke tests for the ai-dispatch parity wave companion commands:
 * aliases / runs / preferences / resume (the send loop itself is covered
 * by SendCommandTest + DispatchSenderTest).
 */
final class DispatchWaveCommandsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-wave-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        putenv('AI_CORE_PREFERENCES_PATH');
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function tester(object $command, string $name): CommandTester
    {
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find($name));
    }

    public function test_aliases_lists_builtin_pool(): void
    {
        $tester = $this->tester(new AliasesCommand(), 'aliases');
        $this->assertSame(0, $tester->execute([]));
        $out = $tester->getDisplay();
        $this->assertStringContainsString('opus', $out);
        $this->assertStringContainsString('claude_cli:opus', $out);
    }

    public function test_aliases_resolves_single_target_as_json(): void
    {
        $tester = $this->tester(new AliasesCommand(), 'aliases');
        $this->assertSame(0, $tester->execute(['target' => 'kimi', '--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertSame('builtin', $decoded['source']);
        $this->assertSame('kimi_cli', $decoded['candidates'][0]['backend']);
    }

    public function test_runs_list_and_show(): void
    {
        $store = new RunStore($this->tmp);
        $store->record(['run_id' => '20260101T000000Z-abc123', 'status' => 'ok', 'requested_target' => 'opus']);

        $list = $this->tester(new RunsCommand($store), 'runs');
        $this->assertSame(0, $list->execute(['action' => 'list']));
        $this->assertStringContainsString('20260101T000000Z-abc123', $list->getDisplay());

        $show = $this->tester(new RunsCommand($store), 'runs');
        $this->assertSame(0, $show->execute(['action' => 'show', 'id' => '20260101T000000Z-abc123']));
        $this->assertStringContainsString('"requested_target": "opus"', $show->getDisplay());

        $missing = $this->tester(new RunsCommand($store), 'runs');
        $this->assertSame(1, $missing->execute(['action' => 'show', 'id' => 'nope']));
    }

    public function test_preferences_init_path_show_cycle(): void
    {
        putenv('AI_CORE_PREFERENCES_PATH=' . $this->tmp . '/preferences.md');

        $path = $this->tester(new PreferencesCommand(), 'preferences');
        $path->execute(['action' => 'path']);
        $this->assertStringContainsString('preferences.md', $path->getDisplay());

        $init = $this->tester(new PreferencesCommand(), 'preferences');
        $this->assertSame(0, $init->execute(['action' => 'init']));
        $this->assertFileExists($this->tmp . '/preferences.md');

        $show = $this->tester(new PreferencesCommand(), 'preferences');
        $this->assertSame(0, $show->execute(['action' => 'show']));
        $this->assertStringContainsString('Scenario picks', $show->getDisplay());

        // Second init is a no-op, not an overwrite.
        $again = $this->tester(new PreferencesCommand(), 'preferences');
        $this->assertSame(0, $again->execute(['action' => 'init']));
        $this->assertStringContainsString('Already exists', $again->getDisplay());
    }

    public function test_resume_requires_session_id_and_known_backend(): void
    {
        $store = new RunStore($this->tmp);

        $noSession = $this->tester(new ResumeCommand($store), 'resume');
        $this->assertSame(1, $noSession->execute(['prompt' => 'hi']));
        $this->assertStringContainsString('--session-id', $noSession->getDisplay());

        $unknown = $this->tester(new ResumeCommand($store), 'resume');
        $this->assertSame(1, $unknown->execute(['prompt' => 'hi', '--session-id' => 'ghost']));
        $this->assertStringContainsString('not found in the run store', $unknown->getDisplay());
    }
}
