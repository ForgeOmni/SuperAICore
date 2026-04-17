<?php

namespace SuperAICore\Tests\Feature;

use SuperAICore\Contracts\Backend;
use SuperAICore\Models\IntegrationConfig;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * End-to-end dispatch test using a stub Backend so we don't hit the network.
 * Verifies:
 *  - explicit backend override path
 *  - engine gate (disabled engine → null)
 *  - cost calculation attached to result
 *  - unknown backend returns null
 */
class DispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_explicit_backend_route_returns_result_with_cost(): void
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('stub_ok', 'hello world', 100, 50, 'claude-sonnet-4-5-20241022'));

        $dispatcher = new Dispatcher($registry, new CostCalculator());
        $result = $dispatcher->dispatch([
            'prompt' => 'ping',
            'backend' => 'stub_ok',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('hello world', $result['text']);
        $this->assertSame('stub_ok', $result['backend']);
        // 100/1M input * $3 + 50/1M output * $15 = 0.0003 + 0.00075 = 0.00105
        $this->assertEqualsWithDelta(0.00105, $result['cost_usd'], 0.0001);
        $this->assertArrayHasKey('duration_ms', $result);
    }

    public function test_unknown_backend_returns_null(): void
    {
        $dispatcher = new Dispatcher(new BackendRegistry(null, []), new CostCalculator());
        $result = $dispatcher->dispatch([
            'prompt' => 'ping',
            'backend' => 'ghost',
        ]);
        $this->assertNull($result);
    }

    public function test_engine_disable_blocks_mapped_dispatcher_backend(): void
    {
        // Map a stub backend under the "anthropic_api" name so the engine
        // gate applies (DISPATCHER_TO_ENGINE: anthropic_api → claude).
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('anthropic_api', 'should not run', 10, 10, 'claude-sonnet-4-5-20241022'));

        // Disable the "claude" engine
        IntegrationConfig::setValue('ai_execution', 'backend_disabled.claude', '1');

        $dispatcher = new Dispatcher($registry, new CostCalculator());
        $result = $dispatcher->dispatch([
            'prompt' => 'ping',
            'backend' => 'anthropic_api',
        ]);

        $this->assertNull($result, 'Dispatcher must refuse a disabled engine');
    }

    public function test_engine_disable_does_not_affect_other_engines(): void
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('openai_api', 'ok', 10, 10, 'gpt-4o'));

        IntegrationConfig::setValue('ai_execution', 'backend_disabled.claude', '1');

        $dispatcher = new Dispatcher($registry, new CostCalculator());
        $result = $dispatcher->dispatch([
            'prompt' => 'ping',
            'backend' => 'openai_api',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('ok', $result['text']);
    }

    private function stubBackend(string $name, string $text, int $inputTokens, int $outputTokens, string $model): Backend
    {
        return new class($name, $text, $inputTokens, $outputTokens, $model) implements Backend {
            public function __construct(
                private string $name,
                private string $text,
                private int $inputTokens,
                private int $outputTokens,
                private string $model,
            ) {}
            public function name(): string { return $this->name; }
            public function isAvailable(array $providerConfig = []): bool { return true; }
            public function generate(array $options): ?array
            {
                return [
                    'text' => $this->text,
                    'model' => $this->model,
                    'usage' => ['input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens],
                    'stop_reason' => null,
                ];
            }
        };
    }
}
