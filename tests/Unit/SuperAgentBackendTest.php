<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;
use SuperAgent\Providers\ProviderRegistry;
use SuperAICore\Backends\SuperAgentBackend;

final class SuperAgentBackendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\SuperAgent\Agent::class)) {
            $this->markTestSkipped('forgeomni/superagent not installed');
        }
        ProviderRegistry::register('sa-test', TestSuperAgentProvider::class);
        TestSuperAgentProvider::reset();
    }

    public function test_happy_path_envelope_carries_cache_tokens_and_turns(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(
            text: 'hi there',
            inputTokens: 100,
            outputTokens: 50,
            cacheRead: 20,
            cacheWrite: 10,
        );

        $backend = new CapturingSuperAgentBackend();
        $r = $backend->generate([
            'prompt' => 'ping',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
            'model' => 'sa-test-model',
        ]);

        $this->assertIsArray($r);
        $this->assertSame('hi there', $r['text']);
        $this->assertSame('sa-test-model', $r['model']);
        $this->assertSame(100, $r['usage']['input_tokens']);
        $this->assertSame(50,  $r['usage']['output_tokens']);
        $this->assertSame(20,  $r['usage']['cache_read_input_tokens']);
        $this->assertSame(10,  $r['usage']['cache_creation_input_tokens']);
        $this->assertArrayHasKey('cost_usd', $r);
        $this->assertSame(1,   $r['turns']);
        $this->assertNull($r['stop_reason']);
    }

    public function test_returns_null_when_text_is_empty(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: '');

        $this->assertNull((new CapturingSuperAgentBackend())->generate([
            'prompt' => 'ping',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]));
    }

    public function test_max_turns_defaults_to_one(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertSame(1, $b->lastAgentConfig['max_turns']);
    }

    public function test_max_turns_is_forwarded(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'max_turns' => 12,
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertSame(12, $b->lastAgentConfig['max_turns']);
    }

    public function test_max_cost_usd_maps_to_max_budget_usd(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'max_cost_usd' => 5.5,
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertSame(5.5, $b->lastAgentConfig['max_budget_usd']);
    }

    public function test_zero_max_cost_usd_omits_budget_key(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'max_cost_usd' => 0,
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertArrayNotHasKey('max_budget_usd', $b->lastAgentConfig);
    }

    public function test_region_routes_through_createWithRegion_and_reaches_provider(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x', 'region' => 'cn'],
        ]);
        // With region set, we hand a pre-built LLMProvider to the Agent
        // instead of the string name — that's the whole point of the
        // branch (Agent's internal config allowlist skips `region`).
        $this->assertInstanceOf(LLMProvider::class, $b->lastAgentConfig['provider']);
        $this->assertSame('cn', TestSuperAgentProvider::$lastRegion);
    }

    public function test_no_region_passes_provider_name_as_string(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertSame('sa-test', $b->lastAgentConfig['provider']);
    }

    public function test_default_path_short_circuits_tool_loader_with_empty_tools(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        // No load_tools key = we hand an empty tools array to Agent, which
        // skips ToolLoader entirely (silences the "Config unavailable …"
        // stderr noise in non-Laravel contexts).
        $this->assertSame([], $b->lastAgentConfig['tools']);
        $this->assertArrayNotHasKey('load_tools', $b->lastAgentConfig);
    }

    public function test_explicit_load_tools_is_forwarded_and_overrides_default(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'load_tools' => 'none',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertSame('none', $b->lastAgentConfig['load_tools']);
        $this->assertArrayNotHasKey('tools', $b->lastAgentConfig);
    }

    public function test_mcp_config_file_loads_without_error_on_empty_servers(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sa-mcp-') . '.json';
        file_put_contents($tmp, json_encode(['mcpServers' => new \stdClass()]));

        try {
            TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
            $r = (new CapturingSuperAgentBackend())->generate([
                'prompt' => 'p',
                'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
                'mcp_config_file' => $tmp,
            ]);
            $this->assertNotNull($r);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_provider_exception_returns_null_instead_of_throwing(): void
    {
        TestSuperAgentProvider::$throw = new \RuntimeException('boom');
        $r = (new CapturingSuperAgentBackend())->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertNull($r);
    }

    private function stubMessage(
        string $text,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cacheRead = 0,
        int $cacheWrite = 0,
    ): AssistantMessage {
        $m = new AssistantMessage();
        $m->content = [ContentBlock::text($text)];
        $m->stopReason = StopReason::EndTurn;
        $m->usage = new Usage(
            $inputTokens,
            $outputTokens,
            $cacheWrite > 0 ? $cacheWrite : null,
            $cacheRead > 0 ? $cacheRead : null,
        );
        return $m;
    }
}

final class CapturingSuperAgentBackend extends SuperAgentBackend
{
    public ?array $lastAgentConfig = null;

    protected function makeAgent(array $agentConfig): \SuperAgent\Agent
    {
        $this->lastAgentConfig = $agentConfig;
        return parent::makeAgent($agentConfig);
    }
}

final class TestSuperAgentProvider implements LLMProvider
{
    public static ?AssistantMessage $nextResponse = null;
    public static ?string $lastRegion = null;
    public static ?\Throwable $throw = null;

    public static function reset(): void
    {
        self::$nextResponse = null;
        self::$lastRegion   = null;
        self::$throw        = null;
    }

    public function __construct(array $config = [])
    {
        self::$lastRegion = $config['region'] ?? null;
    }

    public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): \Generator
    {
        if (self::$throw !== null) {
            $t = self::$throw;
            self::$throw = null;
            throw $t;
        }
        yield self::$nextResponse ?? new AssistantMessage();
    }

    public function formatMessages(array $messages): array { return $messages; }
    public function formatTools(array $tools): array { return []; }
    public function getModel(): string { return 'sa-test-model'; }
    public function setModel(string $model): void {}
    public function name(): string { return 'sa-test'; }
}
