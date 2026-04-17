<?php

namespace SuperAICore\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\SkillArguments;

final class SkillArgumentsTest extends TestCase
{
    public function test_no_schema_when_frontmatter_lacks_arguments(): void
    {
        $this->assertNull(SkillArguments::fromFrontmatter([]));
        $this->assertNull(SkillArguments::fromFrontmatter(['arguments' => null]));
        $this->assertNull(SkillArguments::fromFrontmatter(['arguments' => '']));
        $this->assertNull(SkillArguments::fromFrontmatter(['arguments' => []]));
    }

    public function test_string_form_is_freeform_and_requires_one_arg(): void
    {
        $schema = SkillArguments::fromFrontmatter(['arguments' => 'URL to audit']);

        $this->assertNotNull($schema);
        $this->assertSame(SkillArguments::SHAPE_FREEFORM, $schema->shape);
        $this->assertSame(['input'], $schema->names);
        $this->assertSame(1, $schema->required);

        $this->assertSame(
            'this skill expects one free-form argument',
            $schema->validate([])
        );
        $this->assertNull($schema->validate(['https://example.com']));
        $this->assertNull($schema->validate(['too', 'many']));
    }

    public function test_list_form_is_positional_and_strict(): void
    {
        $schema = SkillArguments::fromFrontmatter([
            'arguments' => ['target_url', 'scope'],
        ]);

        $this->assertNotNull($schema);
        $this->assertSame(SkillArguments::SHAPE_POSITIONAL, $schema->shape);
        $this->assertSame(['target_url', 'scope'], $schema->names);
        $this->assertSame(2, $schema->required);

        $this->assertStringContainsString('missing required argument', $schema->validate(['only-one']));
        $this->assertNull($schema->validate(['a', 'b']));
        $this->assertStringContainsString('extra positional argument', $schema->validate(['a', 'b', 'c']));
    }

    public function test_map_form_is_named_optional(): void
    {
        $schema = SkillArguments::fromFrontmatter([
            'arguments' => [
                'target_url' => 'URL to audit',
                'scope'      => 'Optional scope',
            ],
        ]);

        $this->assertNotNull($schema);
        $this->assertSame(SkillArguments::SHAPE_NAMED, $schema->shape);
        $this->assertSame(['target_url', 'scope'], $schema->names);
        $this->assertSame(0, $schema->required);

        $this->assertNull($schema->validate([]));
        $this->assertNull($schema->validate(['a', 'b']));
    }

    public function test_render_freeform_flat_args(): void
    {
        $schema = SkillArguments::fromFrontmatter(['arguments' => 'URL']);

        $out = $schema->render(['https://example.com']);

        $this->assertStringContainsString('<args>', $out);
        $this->assertStringContainsString('https://example.com', $out);
        $this->assertStringContainsString('</args>', $out);
        $this->assertStringNotContainsString('<arg name=', $out);
    }

    public function test_render_positional_emits_named_arg_tags(): void
    {
        $schema = SkillArguments::fromFrontmatter(['arguments' => ['target_url', 'scope']]);

        $out = $schema->render(['https://example.com', 'full']);

        $this->assertStringContainsString('<arg name="target_url">https://example.com</arg>', $out);
        $this->assertStringContainsString('<arg name="scope">full</arg>', $out);
    }

    public function test_render_escapes_xml_special_chars(): void
    {
        $schema = SkillArguments::fromFrontmatter(['arguments' => ['payload']]);

        $out = $schema->render(['<script>alert("x")</script>']);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_render_freeform_helper_returns_empty_when_no_args(): void
    {
        $this->assertSame('', SkillArguments::renderFreeform([]));
        $this->assertStringContainsString('<args>', SkillArguments::renderFreeform(['hello']));
    }
}
