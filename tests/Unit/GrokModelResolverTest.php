<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\GrokModelResolver;

final class GrokModelResolverTest extends TestCase
{
    public function test_family_alias_resolves(): void
    {
        // grok CLI 0.2.93 — the Build plan routes grok-4.5 as the default.
        $this->assertSame('grok-4.5', GrokModelResolver::resolve('grok'));
        $this->assertSame('grok-composer-2.5-fast', GrokModelResolver::resolve('composer'));
    }

    public function test_known_slug_passes_through(): void
    {
        $this->assertSame('grok-4.5', GrokModelResolver::resolve('grok-4.5'));
        // Legacy single-model lineup stays routable for older accounts.
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
        $this->assertSame('grok-4.5', GrokModelResolver::defaultFor('grok'));
        $this->assertContains('grok', GrokModelResolver::families());
        $this->assertContains('composer', GrokModelResolver::families());
        $this->assertSame('Grok 4.5', GrokModelResolver::displayName('grok-4.5'));
        $this->assertSame('Grok Build (legacy)', GrokModelResolver::displayName('grok-build'));
    }
}
