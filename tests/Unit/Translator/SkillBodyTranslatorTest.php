<?php

namespace SuperAICore\Tests\Unit\Translator;

use PHPUnit\Framework\TestCase;
use SuperAICore\Capabilities\ClaudeCapabilities;
use SuperAICore\Capabilities\CodexCapabilities;
use SuperAICore\Capabilities\GeminiCapabilities;
use SuperAICore\Registry\Skill;
use SuperAICore\Translator\SkillBodyTranslator;

final class SkillBodyTranslatorTest extends TestCase
{
    public function test_gemini_rewrites_tool_references_in_explicit_shapes(): void
    {
        $skill = $this->skill(
            "Use `Read` to open files and call `WebSearch` for facts, then invoke Write to save the summary.\n"
            . "You can also call Bash(...) directly.\n"
        );
        $translator = new SkillBodyTranslator(new GeminiCapabilities());

        $result = $translator->translate($skill);

        // Backtick, call-shape, and lead-verb patterns are all rewritten.
        $this->assertStringContainsString('`read_file`', $result['body']);
        $this->assertStringContainsString('`google_web_search`', $result['body']);
        $this->assertStringContainsString('invoke write_file', $result['body']);
        $this->assertStringContainsString('run_shell_command(', $result['body']);

        // Backend preamble is prepended so Gemini doesn't fall back to
        // codebase_investigator on external-research tasks.
        $this->assertStringContainsString('<!-- gemini-preamble-v1 -->', $result['body']);

        $this->assertSame('read_file', $result['translated']['Read']);
        $this->assertSame('google_web_search', $result['translated']['WebSearch']);
        $this->assertSame('write_file', $result['translated']['Write']);
        $this->assertSame('run_shell_command', $result['translated']['Bash']);
    }

    public function test_prose_references_are_preserved_without_explicit_shape(): void
    {
        // No backticks, no parens, no lead verb — a bare capitalised
        // "Read" or "Write" in a sentence is prose, not a tool call.
        $skill = $this->skill("Read carefully. Write only what matters.\n");
        $translator = new SkillBodyTranslator(new GeminiCapabilities());

        $result = $translator->translate($skill);

        // No rewrite of bare words.
        $this->assertStringContainsString('Read carefully', $result['body']);
        $this->assertStringContainsString('Write only', $result['body']);
        $this->assertArrayNotHasKey('Read', $result['translated']);
        $this->assertArrayNotHasKey('Write', $result['translated']);
    }

    public function test_gemini_reports_unmapped_canonical_tools(): void
    {
        $skill = $this->skill("Spawn an Agent and record progress via TodoWrite.\n");
        $translator = new SkillBodyTranslator(new GeminiCapabilities());

        $result = $translator->translate($skill);

        $this->assertContains('Agent', $result['untranslated']);
        $this->assertContains('TodoWrite', $result['untranslated']);
        $this->assertSame([], $result['translated']);
    }

    public function test_codex_passthrough_body_with_preamble(): void
    {
        $skill = $this->skill("Use Read and Write and Bash.\n");
        $translator = new SkillBodyTranslator(new CodexCapabilities());

        $result = $translator->translate($skill);

        // No tool-name rewrite (codex uses canonical names)…
        $this->assertSame([], $result['translated']);
        $this->assertSame([], $result['untranslated']);
        // …but the codex preamble still gets injected, and the original
        // user body is preserved verbatim at the end.
        $this->assertStringContainsString('<!-- codex-preamble-v1 -->', $result['body']);
        $this->assertStringContainsString('Use Read and Write and Bash.', $result['body']);
    }

    public function test_claude_capabilities_are_identity(): void
    {
        $skill = $this->skill("Call Agent to fan out.\n");
        $translator = new SkillBodyTranslator(new ClaudeCapabilities());

        $result = $translator->translate($skill);

        // Claude is the canonical backend: no rewrite, no preamble.
        $this->assertSame($skill->body, $result['body']);
        $this->assertSame([], $result['translated']);
    }

    public function test_preamble_injection_is_idempotent(): void
    {
        $skill = $this->skill("Use Read to open a file.\n");
        $translator = new SkillBodyTranslator(new GeminiCapabilities());

        $once  = $translator->translate($skill);
        $twice = $translator->translate(new \SuperAICore\Registry\Skill(
            name: 'x', description: null, source: \SuperAICore\Registry\Skill::SOURCE_PROJECT(),
            body: $once['body'], path: '/tmp/x',
        ));

        // Preamble marker appears exactly once even after a second translate.
        $this->assertSame(
            1,
            substr_count($twice['body'], '<!-- gemini-preamble-v1 -->'),
            'gemini preamble must not be stacked'
        );
    }

    public function test_word_boundaries_prevent_partial_matches(): void
    {
        $skill = $this->skill("The ReadMe file explains how to use `Read`.\n");
        $translator = new SkillBodyTranslator(new GeminiCapabilities());

        $result = $translator->translate($skill);

        $this->assertStringContainsString('ReadMe', $result['body']);   // untouched
        $this->assertStringContainsString('`read_file`', $result['body']); // rewritten inside backticks
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
