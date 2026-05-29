<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CursorModelResolver;

final class CursorModelResolverTest extends TestCase
{
    public function test_family_aliases_resolve_to_concrete_ids(): void
    {
        $this->assertSame('composer-2.5-fast', CursorModelResolver::resolve('composer'));
        $this->assertSame('claude-opus-4-8-thinking-high', CursorModelResolver::resolve('opus'));
        $this->assertSame('gpt-5.5-high', CursorModelResolver::resolve('gpt'));
        $this->assertSame('auto', CursorModelResolver::resolve('auto'));
    }

    public function test_known_slugs_pass_through(): void
    {
        $this->assertSame('composer-2.5', CursorModelResolver::resolve('composer-2.5'));
        $this->assertSame('gpt-5.3-codex', CursorModelResolver::resolve('gpt-5.3-codex'));
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

    public function test_catalog_and_default(): void
    {
        $this->assertNotEmpty(CursorModelResolver::catalog());
        $this->assertSame('composer-2.5-fast', CursorModelResolver::defaultFor('composer'));
        $this->assertContains('composer', CursorModelResolver::families());
        $this->assertSame('Composer 2.5', CursorModelResolver::displayName('composer-2.5'));
    }
}
