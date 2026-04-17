<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Support\TablePrefix;
use SuperAICore\Tests\TestCase;

class TablePrefixTest extends TestCase
{
    public function test_default_prefix_is_sac(): void
    {
        // Even without touching config, the default wired in super-ai-core.php is "sac_"
        $this->assertSame('sac_', TablePrefix::value());
        $this->assertSame('sac_ai_providers', TablePrefix::apply('ai_providers'));
    }

    public function test_prefix_is_configurable_and_empty_string_produces_raw_names(): void
    {
        config(['super-ai-core.table_prefix' => '']);
        $this->assertSame('', TablePrefix::value());
        $this->assertSame('ai_providers', TablePrefix::apply('ai_providers'));
    }

    public function test_custom_prefix_is_applied_verbatim(): void
    {
        config(['super-ai-core.table_prefix' => 'pkg_']);
        $this->assertSame('pkg_ai_providers', TablePrefix::apply('ai_providers'));
    }

    public function test_non_string_config_value_falls_back_to_default(): void
    {
        config(['super-ai-core.table_prefix' => 42]);
        $this->assertSame('sac_', TablePrefix::value());
    }
}
