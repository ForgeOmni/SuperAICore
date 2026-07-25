<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\ClaudeModelResolver;

final class ClaudeModelResolverTest extends TestCase
{
    public function test_family_aliases_resolve_to_concrete_ids(): void
    {
        // Lineup per SuperAgent 1.1.10: `opus` moved onto Opus 5, which is a
        // drop-in upgrade over 4.8 at the same $5/$25.
        $this->assertSame('claude-opus-5', ClaudeModelResolver::resolve('opus'));
        $this->assertSame('claude-fable-5', ClaudeModelResolver::resolve('fable'));
        $this->assertSame('claude-sonnet-5', ClaudeModelResolver::resolve('sonnet'));
        $this->assertSame('claude-haiku-4-5-20251001', ClaudeModelResolver::resolve('haiku'));
    }

    /**
     * The whole point of pinning a model id in config is that it runs. The
     * SDK shipped a fix in 1.1.10 for a resolver that silently upgraded
     * pinned ids onto the family's newest entry; this asserts our own
     * resolver never does that.
     */
    public function test_pinned_model_ids_are_never_upgraded(): void
    {
        $this->assertSame('claude-opus-4-8', ClaudeModelResolver::resolve('claude-opus-4-8'));
        $this->assertSame('claude-opus-4-7', ClaudeModelResolver::resolve('claude-opus-4-7'));
        $this->assertSame('claude-opus-4-8[1m]', ClaudeModelResolver::resolve('claude-opus-4-8[1m]'));
        $this->assertSame('claude-opus-5', ClaudeModelResolver::resolve('claude-opus-5'));
    }

    public function test_unknown_and_empty_input_passes_through(): void
    {
        $this->assertSame('some-custom-tune', ClaudeModelResolver::resolve('some-custom-tune'));
        $this->assertNull(ClaudeModelResolver::resolve(null));
        $this->assertNull(ClaudeModelResolver::resolve(''));
    }

    public function test_opus_5_leads_the_opus_catalog(): void
    {
        $opus = array_values(array_filter(
            ClaudeModelResolver::catalog(),
            fn (array $e) => $e['family'] === 'opus',
        ));

        $this->assertSame('claude-opus-5', $opus[0]['slug']);
        // Natively 1M — no `[1m]` beta variant should be offered.
        $this->assertArrayNotHasKey('extended_context', $opus[0]);
        $this->assertSame('claude-opus-5', ClaudeModelResolver::defaultFor('opus'));
    }

    public function test_display_names_cover_the_new_flagship(): void
    {
        $this->assertSame('Opus 5 — 1M context', ClaudeModelResolver::displayName('claude-opus-5'));
        $this->assertSame('Opus 4.8', ClaudeModelResolver::displayName('claude-opus-4-8'));
    }
}
