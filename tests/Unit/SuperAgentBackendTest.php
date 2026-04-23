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

    public function test_idempotency_key_reaches_agent_options_and_echoes_on_envelope(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $r = $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
            'idempotency_key' => 'task:42',
        ]);

        // Agent::run($prompt, ['idempotency_key' => ...]) — SDK 0.9.1 merges
        // into $this->options, and AgentResult echoes it back on completion.
        $this->assertSame('task:42', $b->lastRunOptions['idempotency_key']);
        $this->assertSame('task:42', $r['idempotency_key']);
    }

    public function test_idempotency_key_truncates_to_80_chars_via_sdk(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $long = str_repeat('a', 200);
        $r = $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
            'idempotency_key' => $long,
        ]);

        // The backend forwards the raw 200-char value; the SDK (AgentResult
        // constructor) is responsible for the 80-char truncation.
        $this->assertSame($long, $b->lastRunOptions['idempotency_key']);
        $this->assertSame(80, strlen($r['idempotency_key']));
    }

    public function test_traceparent_is_forwarded_as_per_call_option(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'tracestate'  => 'congo=t61rcWkgMzE',
        ]);

        $this->assertSame(
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            $b->lastRunOptions['traceparent']
        );
        $this->assertSame('congo=t61rcWkgMzE', $b->lastRunOptions['tracestate']);
    }

    public function test_empty_traceparent_is_not_forwarded(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $b = new CapturingSuperAgentBackend();
        $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
            'traceparent' => '',
        ]);

        $this->assertArrayNotHasKey('traceparent', $b->lastRunOptions);
    }

    public function test_classified_provider_exception_returns_null_with_classification(): void
    {
        TestSuperAgentProvider::$throw = new \SuperAgent\Exceptions\Provider\ContextWindowExceededException(
            message: 'context too long',
            provider: 'sa-test',
            statusCode: 400,
        );
        $b = new CapturingSuperAgentBackend();
        $r = $b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);

        $this->assertNull($r);
        $this->assertSame('context_window_exceeded', $b->lastErrorClass);
    }

    public function test_quota_exceeded_classification_matches_sdk_subclass(): void
    {
        TestSuperAgentProvider::$throw = new \SuperAgent\Exceptions\Provider\QuotaExceededException(
            message: 'out of quota',
            provider: 'sa-test',
            statusCode: 429,
        );
        $b = new CapturingSuperAgentBackend();
        $this->assertNull($b->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]));
        $this->assertSame('quota_exceeded', $b->lastErrorClass);
    }

    public function test_envelope_omits_subagents_key_when_no_agent_tool_result_present(): void
    {
        TestSuperAgentProvider::$nextResponse = $this->stubMessage(text: 'ok');
        $r = (new CapturingSuperAgentBackend())->generate([
            'prompt' => 'p',
            'provider_config' => ['provider' => 'sa-test', 'api_key' => 'x'],
        ]);
        $this->assertIsArray($r);
        $this->assertArrayNotHasKey('subagents', $r);
    }

    public function test_extract_subagent_productivity_parses_agent_tool_results(): void
    {
        $backend = new CapturingSuperAgentBackend();
        $msgs = [
            \SuperAgent\Messages\ToolResultMessage::fromResult('tu_1', [
                'status'              => 'completed',
                'agentId'             => 'research-jordan',
                'agentType'           => 'research',
                'content'             => [['type' => 'text', 'text' => 'hi']],
                'filesWritten'        => ['/tmp/a.md', '/tmp/b.md'],
                'toolCallsByName'     => ['Read' => 3, 'Write' => 2],
                'productivityWarning' => null,
                'totalToolUseCount'   => 5,
            ]),
            \SuperAgent\Messages\ToolResultMessage::fromResult('tu_2', [
                'status'              => 'completed_empty',
                'agentId'             => 'advisor-kate',
                'content'             => [],
                'filesWritten'        => [],
                'toolCallsByName'     => [],
                'productivityWarning' => 'zero tool calls — model described plan instead of doing it',
                'totalToolUseCount'   => 0,
            ]),
        ];

        $out = $backend->exposeExtractSubagentProductivity($msgs);

        $this->assertCount(2, $out);
        $this->assertSame('research-jordan', $out[0]['agentId']);
        $this->assertSame('completed',       $out[0]['status']);
        $this->assertSame(['/tmp/a.md', '/tmp/b.md'], $out[0]['filesWritten']);
        $this->assertSame(['Read' => 3, 'Write' => 2], $out[0]['toolCallsByName']);
        $this->assertNull($out[0]['productivityWarning']);
        $this->assertSame(5, $out[0]['totalToolUseCount']);

        $this->assertSame('completed_empty', $out[1]['status']);
        $this->assertStringContainsString('zero tool calls', (string) $out[1]['productivityWarning']);
        $this->assertSame(0, $out[1]['totalToolUseCount']);
    }

    public function test_extract_ignores_non_agent_tool_results(): void
    {
        $backend = new CapturingSuperAgentBackend();
        $msgs = [
            // read_file tool result — a plain string payload, no agentId.
            \SuperAgent\Messages\ToolResultMessage::fromResult('tu_read', 'file contents here'),
            // A JSON payload that lacks `agentId` (not from AgentTool).
            \SuperAgent\Messages\ToolResultMessage::fromResult('tu_other', ['ok' => true, 'rows' => 42]),
            // A payload with `agentId` but without any 0.8.9 productivity
            // fields — looks like a pre-0.8.9 AgentTool shape; must skip.
            \SuperAgent\Messages\ToolResultMessage::fromResult('tu_legacy', [
                'status' => 'completed', 'agentId' => 'legacy', 'totalTokens' => 100,
            ]),
        ];

        $this->assertSame([], $backend->exposeExtractSubagentProductivity($msgs));
    }

    public function test_extract_skips_malformed_json_silently(): void
    {
        $backend = new CapturingSuperAgentBackend();
        // Manually construct a ToolResultMessage whose content string is
        // not valid JSON — must not throw.
        $block = new \SuperAgent\Messages\ContentBlock(
            type: 'tool_result',
            toolUseId: 'tu_bad',
            content: '{not valid json',
        );
        $msg = new \SuperAgent\Messages\ToolResultMessage([$block]);

        $this->assertSame([], $backend->exposeExtractSubagentProductivity([$msg]));
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
    public ?array $lastRunOptions = null;
    public ?string $lastErrorClass = null;

    protected function makeAgent(array $agentConfig): \SuperAgent\Agent
    {
        $this->lastAgentConfig = $agentConfig;
        return parent::makeAgent($agentConfig);
    }

    protected function buildPerCallOptions(array $options): array
    {
        return $this->lastRunOptions = parent::buildPerCallOptions($options);
    }

    protected function logProviderError(\Throwable $e, string $code): void
    {
        $this->lastErrorClass = $code;
        parent::logProviderError($e, $code);
    }

    /** Test accessor for the protected extractor. */
    public function exposeExtractSubagentProductivity(array $messages): array
    {
        return $this->extractSubagentProductivity($messages);
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
