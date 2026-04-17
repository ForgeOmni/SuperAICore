<?php

namespace SuperAICore\Tests\Feature;

use SuperAICore\Models\AiProvider;
use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * End-to-end: migrations run cleanly under the default "sac_" prefix and
 * Eloquent models write/read through the prefixed table names.
 */
class MigrationsAndPrefixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_default_prefix_creates_sac_prefixed_tables(): void
    {
        $this->assertTrue(Schema::hasTable('sac_integration_configs'));
        $this->assertTrue(Schema::hasTable('sac_ai_capabilities'));
        $this->assertTrue(Schema::hasTable('sac_ai_services'));
        $this->assertTrue(Schema::hasTable('sac_ai_service_routing'));
        $this->assertTrue(Schema::hasTable('sac_ai_providers'));
        $this->assertTrue(Schema::hasTable('sac_ai_model_settings'));
        $this->assertTrue(Schema::hasTable('sac_ai_usage_logs'));
        $this->assertTrue(Schema::hasTable('sac_ai_processes'));

        // Raw unprefixed names must NOT exist
        $this->assertFalse(Schema::hasTable('ai_providers'));
        $this->assertFalse(Schema::hasTable('integration_configs'));
    }

    public function test_integration_config_round_trips_via_prefixed_table(): void
    {
        IntegrationConfig::setValue('ai_execution', 'default_backend', 'claude');
        $this->assertSame('claude', IntegrationConfig::getValue('ai_execution', 'default_backend'));

        // Confirm the row actually landed in the prefixed table
        $row = \DB::table('sac_integration_configs')
            ->where('integration_key', 'ai_execution')
            ->where('field_key', 'default_backend')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('claude', $row->value);
    }

    public function test_ai_provider_getTable_reflects_the_configured_prefix(): void
    {
        $model = new AiProvider();
        $this->assertSame('sac_ai_providers', $model->getTable());
    }

    public function test_ai_provider_crud_works_through_prefix_trait(): void
    {
        $provider = AiProvider::create([
            'scope' => 'global',
            'backend' => AiProvider::BACKEND_CLAUDE,
            'name' => 'Test Provider',
            'type' => AiProvider::TYPE_ANTHROPIC,
            'api_key' => 'sk-ant-test',
            'is_active' => true,
        ]);

        $this->assertTrue($provider->exists);
        $this->assertSame(1, AiProvider::count());
        $this->assertSame('Test Provider', AiProvider::find($provider->id)->name);
        // Decrypted round-trip
        $this->assertSame('sk-ant-test', $provider->decrypted_api_key);
    }
}
