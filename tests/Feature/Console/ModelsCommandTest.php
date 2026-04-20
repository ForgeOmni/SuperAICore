<?php

namespace SuperAICore\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use SuperAICore\Console\Commands\ModelsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke tests for the `super-ai-core:models` command. Network-touching paths
 * (`update` without a running remote) aren't exercised here — the command is
 * a thin wrapper around SuperAgent\Providers\ModelCatalog which ships its
 * own unit suite.
 */
final class ModelsCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        $app = new Application();
        $app->add(new ModelsCommand());
        return new CommandTester($app->find('super-ai-core:models'));
    }

    public function test_list_renders_bundled_catalog(): void
    {
        if (!class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            $this->markTestSkipped('SuperAgent ModelCatalog not installed');
        }

        $tester = $this->tester();
        $exit = $tester->execute(['action' => 'list']);
        $this->assertSame(0, $exit);

        $out = $tester->getDisplay();
        // Every bundled catalog has at least the anthropic provider block.
        $this->assertStringContainsString('anthropic', $out);
    }

    public function test_list_filters_by_provider(): void
    {
        if (!class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            $this->markTestSkipped('SuperAgent ModelCatalog not installed');
        }

        $tester = $this->tester();
        $tester->execute(['action' => 'list', '--provider' => 'gemini']);
        $out = $tester->getDisplay();
        $this->assertStringContainsString('gemini', $out);
        // Filter should suppress other provider blocks.
        $this->assertStringNotContainsString('anthropic', $out);
    }

    public function test_status_reports_bundled_and_override_sources(): void
    {
        if (!class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            $this->markTestSkipped('SuperAgent ModelCatalog not installed');
        }

        $tester = $this->tester();
        $exit = $tester->execute(['action' => 'status']);
        $this->assertSame(0, $exit);

        $out = $tester->getDisplay();
        $this->assertStringContainsString('bundled', $out);
        $this->assertStringContainsString('user override', $out);
        $this->assertStringContainsString('Total models loaded', $out);
    }

    public function test_unknown_action_exits_non_zero(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['action' => 'banana']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Unknown action', $tester->getDisplay());
    }
}
