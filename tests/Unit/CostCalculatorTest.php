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
}
