<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\AntigravityModelResolver;

/**
 * Pins the STRICT resolve contract: `agy --model` only accepts full
 * display names, and an unknown value makes print mode dump the model
 * list to stdout with exit 0 — which would be returned as the dispatch
 * "answer". Unknown input must therefore resolve to null (drop the
 * flag), never pass through.
 */
final class AntigravityModelResolverTest extends TestCase
{
    public function test_family_aliases_resolve_to_display_names(): void
    {
        $this->assertSame('Gemini 3.5 Flash (Medium)', AntigravityModelResolver::resolve('flash'));
        $this->assertSame('Gemini 3.1 Pro (High)', AntigravityModelResolver::resolve('pro'));
        $this->assertSame('Claude Sonnet 4.6 (Thinking)', AntigravityModelResolver::resolve('sonnet'));
        $this->assertSame('Claude Opus 4.6 (Thinking)', AntigravityModelResolver::resolve('opus'));
    }

    public function test_slugs_resolve_to_display_names(): void
    {
        $this->assertSame('Gemini 3.5 Flash (Medium)', AntigravityModelResolver::resolve('gemini-3.5-flash'));
        $this->assertSame('Gemini 3.5 Flash (Low)', AntigravityModelResolver::resolve('gemini-3.5-flash-low'));
        $this->assertSame('Gemini 3.1 Pro (Low)', AntigravityModelResolver::resolve('gemini-3.1-pro-low'));
        $this->assertSame('Claude Sonnet 4.6 (Thinking)', AntigravityModelResolver::resolve('claude-sonnet-4-6'));
        $this->assertSame('GPT-OSS 120B (Medium)', AntigravityModelResolver::resolve('gpt-oss-120b'));
    }

    public function test_display_names_pass_through_case_insensitively(): void
    {
        $this->assertSame(
            'Gemini 3.5 Flash (Low)',
            AntigravityModelResolver::resolve('Gemini 3.5 Flash (Low)'),
        );
        $this->assertSame(
            'Gemini 3.5 Flash (Low)',
            AntigravityModelResolver::resolve('gemini 3.5 flash (low)'),
        );
    }

    public function test_unknown_and_empty_resolve_to_null_not_passthrough(): void
    {
        $this->assertNull(AntigravityModelResolver::resolve('grok-4.5'));
        $this->assertNull(AntigravityModelResolver::resolve('gpt-5.6-sol'));
        $this->assertNull(AntigravityModelResolver::resolve('bogus'));
        $this->assertNull(AntigravityModelResolver::resolve(''));
        $this->assertNull(AntigravityModelResolver::resolve(null));
    }

    public function test_catalog_slugs_all_resolve(): void
    {
        foreach (AntigravityModelResolver::CATALOG as $row) {
            $this->assertSame(
                $row['display_name'],
                AntigravityModelResolver::resolve($row['slug']),
                "catalog slug {$row['slug']} must resolve",
            );
        }
    }
}
