<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CliProcessBuilderRegistry;
use SuperAICore\Services\EngineCatalog;
use SuperAICore\Support\EngineDescriptor;

final class CliProcessBuilderRegistryTest extends TestCase
{
    public function test_default_builder_assembles_copilot_argv_from_spec(): void
    {
        $registry = new CliProcessBuilderRegistry(new EngineCatalog([]));

        $cmd = $registry->build('copilot', [
            'prompt' => 'hello',
            'model'  => 'claude-sonnet-4.6',
        ]);

        $this->assertSame('copilot', $cmd[0]);
        $this->assertContains('-p', $cmd);
        $this->assertContains('hello', $cmd);
        $this->assertContains('--output-format=json', $cmd);
        $this->assertContains('--allow-all-tools', $cmd);
        $this->assertContains('--model', $cmd);
        $this->assertContains('claude-sonnet-4.6', $cmd);
    }

    public function test_default_builder_uses_positional_prompt_when_spec_has_no_prompt_flag(): void
    {
        // codex's seeded ProcessSpec has promptFlag=null; prompt is positional.
        $registry = new CliProcessBuilderRegistry(new EngineCatalog([]));

        $cmd = $registry->build('codex', [
            'prompt' => 'explain this',
            'model'  => 'gpt-5.1-codex',
        ]);

        $this->assertSame('codex', $cmd[0]);
        $this->assertContains('exec', $cmd);
        $this->assertContains('--json', $cmd);
        $this->assertContains('--model', $cmd);
        $this->assertContains('gpt-5.1-codex', $cmd);
        $this->assertSame('explain this', $cmd[array_key_last($cmd)]);
    }

    public function test_build_throws_on_unknown_engine(): void
    {
        $registry = new CliProcessBuilderRegistry(new EngineCatalog([]));

        $this->expectException(\InvalidArgumentException::class);
        $registry->build('nope');
    }

    public function test_build_throws_when_engine_has_no_process_spec(): void
    {
        // superagent has is_cli=false and no ProcessSpec.
        $registry = new CliProcessBuilderRegistry(new EngineCatalog([]));

        $this->expectException(\InvalidArgumentException::class);
        $registry->build('superagent');
    }

    public function test_register_override_takes_precedence(): void
    {
        $registry = new CliProcessBuilderRegistry(new EngineCatalog([]));
        $registry->register('copilot', function (EngineDescriptor $e, array $opts): array {
            return ['custom', 'binary', $e->key, $opts['prompt'] ?? ''];
        });

        $cmd = $registry->build('copilot', ['prompt' => 'x']);

        $this->assertSame(['custom', 'binary', 'copilot', 'x'], $cmd);
    }

    public function test_version_and_auth_status_commands_derive_from_spec(): void
    {
        $registry = new CliProcessBuilderRegistry(new EngineCatalog([]));

        $this->assertSame(['claude', '--version'], $registry->versionCommand('claude'));
        $this->assertSame(['claude', 'auth', 'status'], $registry->authStatusCommand('claude'));

        // copilot has no first-class auth-status subcommand.
        $this->assertSame(['copilot', '--version'], $registry->versionCommand('copilot'));
        $this->assertSame([], $registry->authStatusCommand('copilot'));
    }
}
