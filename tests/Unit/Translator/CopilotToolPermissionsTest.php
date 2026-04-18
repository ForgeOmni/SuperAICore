<?php

namespace SuperAICore\Tests\Unit\Translator;

use PHPUnit\Framework\TestCase;
use SuperAICore\Translator\CopilotToolPermissions;

final class CopilotToolPermissionsTest extends TestCase
{
    public function test_translates_canonical_claude_tools_to_copilot_categories(): void
    {
        $perms = new CopilotToolPermissions();

        $out = $perms->translate(['Read', 'Write', 'Bash', 'WebFetch']);

        $this->assertContains('read(*)',  $out);
        $this->assertContains('write(*)', $out);
        $this->assertContains('shell(*)', $out);
        $this->assertContains('url(*)',   $out);
    }

    public function test_parses_parameterised_bash_glob_through_to_shell(): void
    {
        $perms = new CopilotToolPermissions();

        $out = $perms->translate(['Bash(git:*)', 'Read(/etc/*)']);

        $this->assertContains('shell(git:*)', $out);
        $this->assertContains('read(/etc/*)', $out);
    }

    public function test_drops_tools_without_copilot_equivalent(): void
    {
        $perms = new CopilotToolPermissions();

        $out = $perms->translate(['Agent', 'TodoWrite', 'ExitPlanMode']);

        $this->assertSame([], $out);
    }

    public function test_mcp_prefix_strips_to_server_name(): void
    {
        $perms = new CopilotToolPermissions();

        $out = $perms->translate(['mcp__github__create_issue']);

        $this->assertSame(['github'], $out);
    }

    public function test_unknown_tools_collected_for_caller_warning(): void
    {
        $perms = new CopilotToolPermissions();

        $out = $perms->translate(['Read', 'WeirdCustomTool']);

        $this->assertContains('read(*)', $out);
        $this->assertNotContains('WeirdCustomTool', $out);
        $this->assertContains('WeirdCustomTool', $perms->unknown());
    }

    public function test_output_is_deduplicated(): void
    {
        $perms = new CopilotToolPermissions();

        $out = $perms->translate(['Read', 'Glob', 'Grep']); // all map to read(*)

        $this->assertSame(['read(*)'], $out);
    }
}
