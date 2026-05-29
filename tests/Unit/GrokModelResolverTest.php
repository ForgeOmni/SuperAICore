<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\GrokModelResolver;

final class GrokModelResolverTest extends TestCase
{
    public function test_family_alias_resolves(): void
    {
        $this->assertSame('grok-build', GrokModelResolver::resolve('grok'));
    }

    public function test_known_slug_passes_through(): void
    {
        $this->assertSame('grok-build', GrokModelResolver::resolve('grok-build'));
    }

    public function test_null_returns_null(): void
    {
        $this->assertNull(GrokModelResolver::resolve(null));
        $this->assertNull(GrokModelResolver::resolve(''));
    }

    public function test_api_sku_passes_through_for_cli_to_judge(): void
    {
        // grok-4.3 is the metered xAI API SKU, not a CLI model — pass it
        // through so the CLI surfaces its own error rather than us swapping.
        $this->assertSame('grok-4.3', GrokModelResolver::resolve('grok-4.3'));
    }

    public function test_catalog_and_default(): void
    {
        $this->assertNotEmpty(GrokModelResolver::catalog());
        $this->assertSame('grok-build', GrokModelResolver::defaultFor('grok'));
        $this->assertContains('grok', GrokModelResolver::families());
        $this->assertSame('Grok Build', GrokModelResolver::displayName('grok-build'));
    }
}
