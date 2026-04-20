<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Services\GeminiModelResolver;
use SuperAICore\Tests\TestCase;

class GeminiModelResolverTest extends TestCase
{
    public function test_family_aliases_rewrite_to_current_full_ids(): void
    {
        $this->assertSame('gemini-2.5-pro', GeminiModelResolver::resolve('pro'));
        $this->assertSame('gemini-2.5-flash', GeminiModelResolver::resolve('flash'));
        $this->assertSame('gemini-2.5-flash-lite', GeminiModelResolver::resolve('flash-lite'));
    }

    public function test_null_and_empty_passthrough(): void
    {
        $this->assertNull(GeminiModelResolver::resolve(null));
        $this->assertNull(GeminiModelResolver::resolve(''));
    }

    public function test_full_id_is_returned_as_is(): void
    {
        $this->assertSame('gemini-2.5-pro', GeminiModelResolver::resolve('gemini-2.5-pro'));
        // Unknown slugs still pass through — Google's API is authoritative, not us
        $this->assertSame('gemini-3.0-preview', GeminiModelResolver::resolve('gemini-3.0-preview'));
    }

    public function test_defaultFor_returns_pro_for_unknown_family(): void
    {
        $this->assertSame('gemini-2.5-pro', GeminiModelResolver::defaultFor('pro'));
        $this->assertSame('gemini-2.5-flash', GeminiModelResolver::defaultFor('flash'));
        // Unknown family → pro
        $this->assertSame('gemini-2.5-pro', GeminiModelResolver::defaultFor('ultra'));
    }

    public function test_catalog_is_non_empty_and_has_expected_shape(): void
    {
        $catalog = GeminiModelResolver::catalog();
        $this->assertNotEmpty($catalog);
        foreach ($catalog as $entry) {
            $this->assertArrayHasKey('slug', $entry);
            $this->assertArrayHasKey('display_name', $entry);
        }
    }

    public function test_model_catalog_fallback_resolves_gemini_shorthand(): void
    {
        if (!class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            $this->markTestSkipped('SuperAgent ModelCatalog not installed');
        }

        // `gemini` (bare) is NOT in our local ALIASES, but the bundled catalog
        // lists it as an alias for gemini-2.0-flash. The resolver should fall
        // through and return a gemini-prefixed id.
        $resolved = GeminiModelResolver::resolve('gemini');
        $this->assertNotNull($resolved);
        $this->assertStringStartsWith('gemini', (string) $resolved);
        // And it should NOT be the bare input (verifying we actually resolved)
        $this->assertNotSame('gemini', $resolved);
    }

    public function test_resolver_does_not_leak_non_gemini_catalog_matches(): void
    {
        // `opus` resolves to a Claude model in the shared catalog. Gemini's
        // resolver must not return a Claude id — it should pass through.
        $this->assertSame('opus', GeminiModelResolver::resolve('opus'));
    }
}
