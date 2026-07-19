<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CursorModelResolver;

final class CursorModelResolverTest extends TestCase
{
    public function test_family_aliases_resolve_to_concrete_ids(): void
    {
        // Lineup verified 2026-07-12: composer-2.5 is the account's
        // "current" pick; GPT family lands on the newest 5.6 Sol tier.
        $this->assertSame('composer-2.5', CursorModelResolver::resolve('composer'));
        $this->assertSame('claude-opus-4-8-thinking-high', CursorModelResolver::resolve('opus'));
        $this->assertSame('gpt-5.6-sol-high', CursorModelResolver::resolve('gpt'));
        $this->assertSame('auto', CursorModelResolver::resolve('auto'));
    }

    public function test_new_proxied_family_aliases_resolve(): void
    {
        $this->assertSame('claude-fable-5-thinking-high', CursorModelResolver::resolve('fable'));
        $this->assertSame('claude-sonnet-5-thinking-high', CursorModelResolver::resolve('sonnet'));
        $this->assertSame('cursor-grok-4.5-high', CursorModelResolver::resolve('grok'));
        $this->assertSame('gemini-3.5-flash', CursorModelResolver::resolve('gemini'));
        $this->assertSame('kimi-k2.7-code', CursorModelResolver::resolve('kimi'));
        $this->assertSame('glm-5.2-high', CursorModelResolver::resolve('glm'));
    }

    public function test_known_slugs_pass_through(): void
    {
        $this->assertSame('composer-2.5-fast', CursorModelResolver::resolve('composer-2.5-fast'));
        $this->assertSame('gpt-5.3-codex', CursorModelResolver::resolve('gpt-5.3-codex'));
        $this->assertSame('glm-5.2-max', CursorModelResolver::resolve('glm-5.2-max'));
    }

    public function test_claude_context_tag_and_date_stamp_map_to_thinking_sku(): void
    {
        // Host pipes a Claude-CLI shaped id; strip [1m]/date then land on the
        // Cursor Opus thinking SKU for the family.
        $this->assertSame('claude-opus-4-8-thinking-high', CursorModelResolver::resolve('claude-opus-4-8[1m]'));
        $this->assertSame('claude-opus-4-8-thinking-high', CursorModelResolver::resolve('claude-opus-4-8-20260528'));
    }

    public function test_null_and_empty_return_null(): void
    {
        $this->assertNull(CursorModelResolver::resolve(null));
        $this->assertNull(CursorModelResolver::resolve(''));
    }

    public function test_unknown_passes_through_for_cli_to_reject(): void
    {
        $this->assertSame('totally-made-up', CursorModelResolver::resolve('totally-made-up'));
    }

    public function test_legacy_grok_slug_maps_to_renamed_row(): void
    {
        // cursor-agent 2026.07 renamed grok-4.5-* to cursor-grok-4.5-* and
        // capped effort at high; stale configs keep resolving.
        $this->assertSame('cursor-grok-4.5-high', CursorModelResolver::resolve('grok-4.5-xhigh'));
        $this->assertSame('cursor-grok-4.5-high', CursorModelResolver::resolve('cursor-grok-4.5-high'));
    }

    public function test_catalog_and_default(): void
    {
        $this->assertNotEmpty(CursorModelResolver::catalog());
        $this->assertSame('composer-2.5', CursorModelResolver::defaultFor('composer'));
        $this->assertContains('composer', CursorModelResolver::families());
        $this->assertContains('fable', CursorModelResolver::families());
        $this->assertSame('Composer 2.5', CursorModelResolver::displayName('composer-2.5'));
        $this->assertSame('Kimi K2.7 Code', CursorModelResolver::displayName('kimi-k2.7-code'));
    }
}
