<?php

namespace SuperAICore\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\FrontmatterParser;

final class FrontmatterParserTest extends TestCase
{
    public function test_parses_basic_scalar_frontmatter(): void
    {
        [$fm, $body] = FrontmatterParser::parse("---\nname: init\ndescription: Bootstrap project\n---\nBody line 1\nBody line 2\n");

        $this->assertSame('init', $fm['name']);
        $this->assertSame('Bootstrap project', $fm['description']);
        $this->assertSame("Body line 1\nBody line 2\n", $body);
    }

    public function test_returns_empty_frontmatter_when_absent(): void
    {
        [$fm, $body] = FrontmatterParser::parse("No frontmatter here.\nJust body.");

        $this->assertSame([], $fm);
        $this->assertSame("No frontmatter here.\nJust body.", $body);
    }

    public function test_parses_yaml_list_with_indented_dashes(): void
    {
        $raw = "---\nname: beta\nallowed-tools:\n  - Read\n  - Write\n---\nbody";
        [$fm, $body] = FrontmatterParser::parse($raw);

        $this->assertSame(['Read', 'Write'], $fm['allowed-tools']);
        $this->assertSame('body', $body);
    }

    public function test_parses_inline_flow_sequence(): void
    {
        [$fm] = FrontmatterParser::parse("---\ntags: [alpha, beta, gamma]\n---\n");

        $this->assertSame(['alpha', 'beta', 'gamma'], $fm['tags']);
    }

    public function test_unclosed_frontmatter_returns_raw(): void
    {
        $raw = "---\nname: broken\nno closing delimiter\nmore body\n";
        [$fm, $body] = FrontmatterParser::parse($raw);

        $this->assertSame([], $fm);
        $this->assertSame($raw, $body);
    }

    public function test_tolerates_crlf_line_endings_and_bom(): void
    {
        $raw = "\xEF\xBB\xBF---\r\nname: crlf\r\n---\r\nbody\r\n";
        [$fm, $body] = FrontmatterParser::parse($raw);

        $this->assertSame('crlf', $fm['name']);
        $this->assertStringContainsString('body', $body);
    }

    public function test_coerces_booleans_and_null(): void
    {
        [$fm] = FrontmatterParser::parse("---\nenabled: true\ndisabled: false\nempty: null\n---\n");

        $this->assertTrue($fm['enabled']);
        $this->assertFalse($fm['disabled']);
        $this->assertNull($fm['empty']);
    }

    public function test_unquotes_quoted_values(): void
    {
        [$fm] = FrontmatterParser::parse("---\nname: \"hello: world\"\nalias: 'a-b-c'\n---\n");

        $this->assertSame('hello: world', $fm['name']);
        $this->assertSame('a-b-c', $fm['alias']);
    }
}
