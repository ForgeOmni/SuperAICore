<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Contracts\Backend;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\DispatchSender;
use SuperAICore\Services\RunStore;

final class DispatchSenderTest extends TestCase
{
    private const ALL_DISABLED = [
        'anthropic_api' => ['enabled' => false], 'openai_api' => ['enabled' => false],
        'superagent' => ['enabled' => false], 'squad' => ['enabled' => false],
        'claude_cli' => ['enabled' => false], 'codex_cli' => ['enabled' => false],
        'gemini_cli' => ['enabled' => false], 'copilot_cli' => ['enabled' => false],
        'kiro_cli' => ['enabled' => false], 'kimi_cli' => ['enabled' => false],
        'qwen_cli' => ['enabled' => false], 'cursor_cli' => ['enabled' => false],
        'grok_cli' => ['enabled' => false], 'gemini_api' => ['enabled' => false],
    ];

    private string $runsDir;

    protected function setUp(): void
    {
        $this->runsDir = sys_get_temp_dir() . '/sac-sender-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->runsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->runsDir);
    }

    /** @param array<string, array|null|callable> $resultsByBackend */
    private function sender(array $resultsByBackend, array $backendNames): DispatchSender
    {
        $registry = new BackendRegistry(null, self::ALL_DISABLED);
        foreach ($backendNames as $name) {
            $registry->register($this->fakeBackend($name));
        }

        $dispatcher = new class($registry, new CostCalculator(['x' => ['input' => 0, 'output' => 0]]), $resultsByBackend) extends Dispatcher {
            public array $seenOptions = [];

            public function __construct(BackendRegistry $b, CostCalculator $c, private array $results)
            {
                parent::__construct($b, $c);
            }

            public function dispatch(array $options): ?array
            {
                $this->seenOptions[] = $options;
                $result = $this->results[$options['backend']] ?? null;
                return is_callable($result) ? $result($options) : $result;
            }
        };

        return new DispatchSender($dispatcher, $registry, new RunStore($this->runsDir));
    }

    private function fakeBackend(string $name): Backend
    {
        return new class($name) implements Backend {
            public function __construct(private string $name) {}
            public function name(): string { return $this->name; }
            public function generate(array $options): ?array { return null; }
            public function isAvailable(array $providerConfig = []): bool { return true; }
        };
    }

    public function test_first_candidate_success_is_not_degraded(): void
    {
        $sender = $this->sender([
            'claude_cli' => ['text' => 'hello', 'model' => 'claude-opus-4-8', 'usage' => [], 'cost_usd' => 0.01, 'session_id' => 'sess-1', 'exit_code' => 0],
        ], ['claude_cli']);

        $result = $sender->send('opus', 'builtin', [['backend' => 'claude_cli', 'model' => 'opus']], 'hi');

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['degraded']);
        $this->assertSame('claude_cli', $result['backend_used']);
        $this->assertSame('claude-opus-4-8', $result['model_used']);
        $this->assertSame('sess-1', $result['session_id']);
        $this->assertCount(1, $result['route_trace']);
        $this->assertNotEmpty($result['run_id']);
    }

    public function test_quota_failure_falls_through_and_marks_degraded(): void
    {
        $sender = $this->sender([
            'claude_cli' => ['text' => 'You exceeded your current quota', 'exit_code' => 1],
            'gemini_cli' => ['text' => 'answer from gemini', 'model' => 'gemini-pro', 'exit_code' => 0],
        ], ['claude_cli', 'gemini_cli']);

        $result = $sender->send('review', 'config', [
            ['backend' => 'claude_cli', 'model' => 'opus'],
            ['backend' => 'gemini_cli', 'model' => 'pro'],
        ], 'review this');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['degraded']);
        $this->assertSame('gemini_cli', $result['backend_used']);
        $this->assertSame('failed', $result['route_trace'][0]['status']);
        $this->assertSame('quota', $result['route_trace'][0]['failure_class']);
        $this->assertSame('ok', $result['route_trace'][1]['status']);
    }

    public function test_runtime_failure_fails_closed_without_trying_next_candidate(): void
    {
        $sender = $this->sender([
            'claude_cli' => ['text' => '', 'exit_code' => 137, 'log_file' => null],
            'gemini_cli' => ['text' => 'should never run', 'exit_code' => 0],
        ], ['claude_cli', 'gemini_cli']);

        $result = $sender->send('opus', 'builtin', [
            ['backend' => 'claude_cli', 'model' => 'opus'],
            ['backend' => 'gemini_cli', 'model' => 'pro'],
        ], 'do it');

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['status']);
        $this->assertCount(1, $result['route_trace']);
    }

    public function test_unregistered_backend_is_skipped(): void
    {
        $sender = $this->sender([
            'gemini_cli' => ['text' => 'fallback answer', 'exit_code' => 0],
        ], ['gemini_cli']);

        $result = $sender->send('x', 'config', [
            ['backend' => 'kimi_cli', 'model' => null],
            ['backend' => 'gemini_cli', 'model' => null],
        ], 'hi');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['degraded']);
        $this->assertSame('backend_not_registered', $result['route_trace'][0]['reason']);
    }

    public function test_all_candidates_failing_reports_exhausted(): void
    {
        $sender = $this->sender([
            'claude_cli' => null,
            'gemini_cli' => null,
        ], ['claude_cli', 'gemini_cli']);

        $result = $sender->send('x', 'builtin', [
            ['backend' => 'claude_cli', 'model' => null],
            ['backend' => 'gemini_cli', 'model' => null],
        ], 'hi');

        $this->assertFalse($result['ok']);
        $this->assertSame('exhausted', $result['status']);
        $this->assertCount(2, $result['route_trace']);
    }

    public function test_resume_session_id_flows_to_dispatch_options(): void
    {
        $registrySeen = null;
        $sender = $this->sender([
            'claude_cli' => function (array $options) use (&$registrySeen) {
                $registrySeen = $options;
                return ['text' => 'resumed', 'exit_code' => 0, 'session_id' => 'new-sess'];
            },
        ], ['claude_cli']);

        $result = $sender->send('old-sess', 'resume', [['backend' => 'claude_cli', 'model' => null]], 'follow up', [
            'resume_session_id' => 'old-sess',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('old-sess', $registrySeen['resume_session_id']);
        $this->assertSame('new-sess', $result['session_id']);
    }
}
