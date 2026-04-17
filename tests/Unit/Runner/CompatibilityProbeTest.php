<?php

namespace SuperAICore\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use SuperAICore\Capabilities\ClaudeCapabilities;
use SuperAICore\Capabilities\CodexCapabilities;
use SuperAICore\Capabilities\GeminiCapabilities;
use SuperAICore\Registry\Skill;
use SuperAICore\Runner\CompatibilityProbe;

final class CompatibilityProbeTest extends TestCase
{
    public function test_claude_always_compatible_even_with_agent(): void
    {
        $probe = new CompatibilityProbe(new ClaudeCapabilities());
        $result = $probe->probe($this->skill("Call Agent to fan out and Read files."));

        $this->assertSame(CompatibilityProbe::COMPATIBLE, $result['status']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_agent_tool_makes_gemini_incompatible(): void
    {
        $probe = new CompatibilityProbe(new GeminiCapabilities());
        $result = $probe->probe($this->skill("Spawn an Agent to research."));

        $this->assertSame(CompatibilityProbe::INCOMPATIBLE, $result['status']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertStringContainsString('Agent', $result['reasons'][0]);
    }

    public function test_agent_tool_makes_codex_incompatible(): void
    {
        $probe = new CompatibilityProbe(new CodexCapabilities());
        $result = $probe->probe($this->skill("Use Agent to spawn a child."));

        $this->assertSame(CompatibilityProbe::INCOMPATIBLE, $result['status']);
    }

    public function test_gemini_degraded_on_unmapped_canonical_tool(): void
    {
        $probe = new CompatibilityProbe(new GeminiCapabilities());
        $result = $probe->probe($this->skill("Use Read, then call TodoWrite."));

        $this->assertSame(CompatibilityProbe::DEGRADED, $result['status']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertStringContainsString('TodoWrite', implode(' ', $result['reasons']));
    }

    public function test_codex_empty_map_skips_degraded_check(): void
    {
        // Codex treats canonical names as native (empty map ⇒ identity).
        // Probe doesn't flag unknown tools because we can't distinguish
        // "native" from "missing" when the map is empty — limitation documented.
        $probe = new CompatibilityProbe(new CodexCapabilities());
        $result = $probe->probe($this->skill("Use Read, Write, Edit, Bash, Glob, Grep."));

        $this->assertSame(CompatibilityProbe::COMPATIBLE, $result['status']);
    }

    public function test_gemini_fully_mapped_skill_is_compatible(): void
    {
        $probe = new CompatibilityProbe(new GeminiCapabilities());
        $result = $probe->probe($this->skill("Read and Write files, call Bash, use WebSearch."));

        $this->assertSame(CompatibilityProbe::COMPATIBLE, $result['status']);
    }

    public function test_agent_plus_unmapped_reports_both_reasons_as_incompatible(): void
    {
        $probe = new CompatibilityProbe(new GeminiCapabilities());
        $result = $probe->probe($this->skill("Spawn Agent and use TodoWrite."));

        $this->assertSame(CompatibilityProbe::INCOMPATIBLE, $result['status']);
        $this->assertGreaterThanOrEqual(2, count($result['reasons']));
    }

    private function skill(string $body): Skill
    {
        return new Skill(
            name: 'test',
            description: null,
            source: Skill::SOURCE_PROJECT(),
            body: $body,
            path: '/tmp/fake/SKILL.md',
        );
    }
}
