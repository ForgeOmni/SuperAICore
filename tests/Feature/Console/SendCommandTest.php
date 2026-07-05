<?php

namespace SuperAICore\Tests\Feature\Console;

use PHPUnit\Framework\TestCase;
use SuperAICore\Contracts\Backend;
use SuperAICore\Console\Commands\SendCommand;
use SuperAICore\Services\AliasRouter;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\DispatchSender;
use SuperAICore\Services\RunStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SendCommandTest extends TestCase
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
        $this->runsDir = sys_get_temp_dir() . '/sac-sendcmd-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->runsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->runsDir);
    }

    private function tester(?array $dispatchResult): CommandTester
    {
        $registry = new BackendRegistry(null, self::ALL_DISABLED);
        $registry->register(new class implements Backend {
            public function name(): string { return 'claude_cli'; }
            public function generate(array $options): ?array { return null; }
            public function isAvailable(array $providerConfig = []): bool { return true; }
        });

        $dispatcher = new class($registry, new CostCalculator(['x' => ['input' => 0, 'output' => 0]]), $dispatchResult) extends Dispatcher {
            public function __construct(BackendRegistry $b, CostCalculator $c, private ?array $result)
            {
                parent::__construct($b, $c);
            }

            public function dispatch(array $options): ?array
            {
                return $this->result;
            }
        };

        $sender = new DispatchSender($dispatcher, $registry, new RunStore($this->runsDir));

        $app = new Application();
        $app->add(new SendCommand($registry, $sender));
        return new CommandTester($app->find('send'));
    }

    public function test_json_result_carries_full_contract(): void
    {
        $tester = $this->tester([
            'text' => 'PONG', 'model' => 'claude-opus-4-8', 'usage' => [],
            'cost_usd' => 0.02, 'session_id' => 'sess-42', 'exit_code' => 0,
        ]);

        $exit = $tester->execute(['target' => 'opus', 'prompt' => 'ping', '--json-result' => true, '--no-check' => true]);
        $this->assertSame(0, $exit);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('PONG', $decoded['text']);
        $this->assertSame('opus', $decoded['requested_target']);
        $this->assertSame('builtin', $decoded['route_source']);
        $this->assertSame('claude_cli', $decoded['backend_used']);
        $this->assertSame('sess-42', $decoded['session_id']);
        $this->assertFalse($decoded['degraded']);
        $this->assertIsArray($decoded['route_trace']);
        $this->assertNotEmpty($decoded['run_id']);
    }

    public function test_failed_dispatch_exits_nonzero_with_trace(): void
    {
        $tester = $this->tester(null);
        $exit = $tester->execute(['target' => 'opus', 'prompt' => 'ping', '--no-check' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Dispatch failed', $tester->getDisplay());
        $this->assertStringContainsString('claude_cli', $tester->getDisplay());
    }

    public function test_missing_prompt_errors_out(): void
    {
        $tester = $this->tester(null);
        $exit = $tester->execute(['target' => 'opus']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--prompt-file', $tester->getDisplay());
    }

    public function test_prompt_file_is_read(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sac-prompt-');
        file_put_contents($file, 'file prompt');
        try {
            $tester = $this->tester(['text' => 'ok', 'exit_code' => 0]);
            $exit = $tester->execute(['target' => 'opus', '--prompt-file' => $file, '--json-result' => true, '--no-check' => true]);
            $this->assertSame(0, $exit);
        } finally {
            @unlink($file);
        }
    }
}
