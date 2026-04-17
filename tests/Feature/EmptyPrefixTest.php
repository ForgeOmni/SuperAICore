<?php

namespace SuperAICore\Tests\Feature;

use SuperAICore\Models\AiProvider;
use SuperAICore\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * Users that already depend on the raw "ai_providers" naming can opt out of
 * the prefix by setting it to the empty string.
 */
class EmptyPrefixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('super-ai-core.table_prefix', '');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_empty_prefix_creates_unprefixed_tables(): void
    {
        $this->assertTrue(Schema::hasTable('ai_providers'));
        $this->assertTrue(Schema::hasTable('integration_configs'));

        $this->assertFalse(Schema::hasTable('sac_ai_providers'));
    }

    public function test_model_getTable_returns_raw_name_when_prefix_is_empty(): void
    {
        $this->assertSame('ai_providers', (new AiProvider())->getTable());
    }

    public function test_crud_works_against_unprefixed_table(): void
    {
        $provider = AiProvider::create([
            'scope' => 'global',
            'backend' => AiProvider::BACKEND_CODEX,
            'name' => 'No-prefix provider',
            'type' => AiProvider::TYPE_OPENAI,
            'api_key' => 'sk-openai-test',
            'is_active' => false,
        ]);
        $this->assertTrue($provider->exists);
        $this->assertSame(1, AiProvider::count());
    }
}
