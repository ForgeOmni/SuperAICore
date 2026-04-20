<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Services\CostCalculator;
use SuperAICore\Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    public function test_unknown_model_is_free(): void
    {
        $calc = new CostCalculator();
        $this->assertSame(0.0, $calc->calculate('not-a-real-model', 1_000_000, 1_000_000));
    }

    public function test_known_model_applies_per_million_pricing(): void
    {
        // From config/super-ai-core.php: claude-sonnet-4-5-20241022 → 3 input, 15 output per 1M
        $calc = new CostCalculator();
        // 1M input + 1M output = 3 + 15 = 18 USD
        $this->assertEqualsWithDelta(18.0, $calc->calculate('claude-sonnet-4-5-20241022', 1_000_000, 1_000_000), 0.0001);
    }

    public function test_partial_token_counts_scale_linearly(): void
    {
        // 500k input + 250k output for sonnet → (0.5 * 3) + (0.25 * 15) = 1.5 + 3.75 = 5.25
        $calc = new CostCalculator();
        $this->assertEqualsWithDelta(5.25, $calc->calculate('claude-sonnet-4-5-20241022', 500_000, 250_000), 0.0001);
    }

    public function test_zero_tokens_is_zero_cost(): void
    {
        $calc = new CostCalculator();
        $this->assertSame(0.0, $calc->calculate('claude-sonnet-4-5-20241022', 0, 0));
    }

    public function test_pricing_override_via_config_wins_over_default(): void
    {
        config(['super-ai-core.model_pricing.fake-model' => ['input' => 10.0, 'output' => 20.0]]);
        $calc = new CostCalculator();
        // 1M input + 1M output = 10 + 20 = 30
        $this->assertEqualsWithDelta(30.0, $calc->calculate('fake-model', 1_000_000, 1_000_000), 0.0001);
    }

    public function test_gemini_2_5_pro_pricing(): void
    {
        // Config: gemini-2.5-pro → input 1.25, output 10.00 per 1M
        $calc = new CostCalculator();
        $this->assertEqualsWithDelta(11.25, $calc->calculate('gemini-2.5-pro', 1_000_000, 1_000_000), 0.0001);
    }

    public function test_gemini_flash_lite_is_cheapest_tier(): void
    {
        $calc = new CostCalculator();
        // gemini-2.5-flash-lite → input 0.10, output 0.40
        $this->assertEqualsWithDelta(0.50, $calc->calculate('gemini-2.5-flash-lite', 1_000_000, 1_000_000), 0.0001);
    }

    public function test_subscription_billed_models_always_cost_zero(): void
    {
        $calc = new CostCalculator([
            'copilot:claude-sonnet-4-5' => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        ]);
        // Even with millions of tokens, subscription billing reports $0.
        $this->assertSame(0.0, $calc->calculate('claude-sonnet-4-5', 999_000_000, 999_000_000, 'copilot_cli'));
    }

    public function test_billing_model_helper_returns_subscription_for_copilot_entry(): void
    {
        $calc = new CostCalculator([
            'copilot:claude-sonnet-4-5' => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        ]);
        $this->assertSame('subscription', $calc->billingModel('claude-sonnet-4-5', 'copilot_cli'));
        $this->assertSame('usage',        $calc->billingModel('not-a-real-model', 'unknown'));
    }

    public function test_longest_prefix_match_avoids_wrong_family_collision(): void
    {
        // Pricing has both 'gpt-4o' (cheap) and 'gpt-5' (expensive); a model
        // 'gpt-5.1-codex' must NOT pick up the gpt-4o rate.
        $calc = new CostCalculator([
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            'gpt-5'  => ['input' => 5.00, 'output' => 15.00],
        ]);
        $this->assertEqualsWithDelta(20.0, $calc->calculate('gpt-5.1-codex', 1_000_000, 1_000_000), 0.0001);
    }

    public function test_codex_pricing_resolves_for_gpt_5_family(): void
    {
        // Hits the seeded gpt-5 rate from config (5/15) → 5+15 = 20
        $calc = new CostCalculator();
        $this->assertEqualsWithDelta(20.0, $calc->calculate('gpt-5', 1_000_000, 1_000_000), 0.0001);
    }

    public function test_unknown_model_falls_through_to_superagent_model_catalog(): void
    {
        if (!class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            $this->markTestSkipped('SuperAgent ModelCatalog not installed');
        }

        // Pick a bundled catalog row that neither the seeded config keys nor
        // their prefixes match. `claude-3-haiku-20240307` is ideal:
        //   - config has `claude-opus-*`, `claude-sonnet-*`, `claude-haiku-4-5*` — none match
        //   - bundled catalog ships this row at input 0.25, output 1.25
        //   - 1M + 1M → 0.25 + 1.25 = 1.50
        $calc = new CostCalculator([
            'sentinel-so-constructor-does-not-fall-back-to-config' => ['input' => 1.0, 'output' => 1.0],
        ]);
        $cost = $calc->calculate('claude-3-haiku-20240307', 1_000_000, 1_000_000);
        $this->assertEqualsWithDelta(1.50, $cost, 0.01, 'ModelCatalog fallback should price claude-3-haiku-20240307');
    }

    public function test_config_pricing_wins_over_catalog_fallback(): void
    {
        // Even if the catalog would resolve this, an explicit config override
        // is authoritative — no surprise swaps when a host publishes prices.
        $calc = new CostCalculator([
            'claude-3-haiku-20240307' => ['input' => 999.0, 'output' => 999.0],
        ]);
        $this->assertEqualsWithDelta(
            1998.0,
            $calc->calculate('claude-3-haiku-20240307', 1_000_000, 1_000_000),
            0.0001
        );
    }

    public function test_catalog_fallback_returns_zero_when_neither_source_knows_model(): void
    {
        $calc = new CostCalculator([
            'sentinel-key' => ['input' => 1.0, 'output' => 1.0],
        ]);
        $this->assertSame(0.0, $calc->calculate('definitely-not-a-real-model-anywhere-xyz', 1_000_000, 1_000_000));
    }
}
