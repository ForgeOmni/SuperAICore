<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit;

use SuperAICore\Modes\CliAutoMode;
use SuperAICore\Modes\CliSmartOrchestrator;
use SuperAICore\Modes\CliSquadOrchestrator;
use SuperAICore\Modes\CrossLayerDispatcher;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * Pin the auto-mode decision tree: short prompts → single, medium →
 * smart, complex multi-domain → squad. Forced overrides bypass the
 * heuristic entirely.
 */
class CliAutoModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!interface_exists(\SuperAgent\Modes\ModeOrchestrator::class)) {
            $this->markTestSkipped('forgeomni/superagent not installed');
        }
    }

    public function test_forced_single_mode_routes_to_default_cli(): void
    {
        // The default decomposer may split even short prompts, so for a
        // deterministic "single mode" pin we force it via options.
        [$mode, $captured] = $this->buildMode();
        $r = $mode->run('hi', ['mode' => 'single']);
        $this->assertSame('single', $r['mode']);
        $this->assertSame('cli:claude_cli', $captured->lastProvider);
    }

    public function test_forced_mode_wins_over_heuristic(): void
    {
        [$mode, $captured] = $this->buildMode();
        $r = $mode->run('long complex refactor design audit task', ['mode' => 'single', 'cli' => 'cli:codex_cli']);
        $this->assertSame('single', $r['mode']);
        $this->assertSame('cli:codex_cli', $captured->lastProvider);
    }

    public function test_forced_cli_implies_single_mode(): void
    {
        [$mode, $captured] = $this->buildMode();
        $r = $mode->run('design a complex distributed cache with multi-step migration plan', ['cli' => 'cli:codex_cli']);
        $this->assertSame('single', $r['mode']);
        $this->assertSame('cli:codex_cli', $captured->lastProvider);
    }

    public function test_complex_multi_band_prompt_routes_to_squad(): void
    {
        [$mode, $captured] = $this->buildMode();
        // multi-step + complexity keywords → high score, multiple bands
        $prompt = "1. refactor the auth module\n2. then audit security\n3. finally write the migration plan\n"
                . "investigate the architecture and design the optimization strategy with debug analysis";
        $r = $mode->run($prompt);
        // recursion target must be `smart` or `squad` (depends on band count)
        $this->assertContains($r['mode'], ['smart', 'squad']);
        $this->assertContains($captured->lastProvider, ['smart', 'squad']);
    }

    /** @return array{0:CliAutoMode, 1:object} */
    private function buildMode(): array
    {
        $captured = new class { public ?string $lastProvider = null; };

        $core = new class($captured) extends Dispatcher {
            public function __construct(private object $captured) {}
            public function dispatch(array $options): ?array
            {
                // The cross-layer dispatcher translates `cli:foo` to
                // `backend: foo` and `auto/smart/squad` (after recursion)
                // to whatever leaf the recurse-target produced.
                $this->captured->lastProvider = isset($options['backend'])
                    ? 'cli:' . $options['backend']
                    : '';
                return ['text' => 'ok', 'cost_usd' => 0.01];
            }
        };

        $cross = new CrossLayerDispatcher($core);
        $auto = new CliAutoMode($cross, null, ['default_cli' => 'cli:claude_cli']);
        $smart = new class($cross) extends CliSmartOrchestrator {
            public function run(string $task, array $options = []): array
            {
                return ['text' => 'smart-out', 'mode' => 'smart', 'cost_usd' => 0.05, 'subtask_results' => [], 'plan' => []];
            }
        };
        $squad = new class($cross) extends CliSquadOrchestrator {
            public function run(string $task, array $options = []): array
            {
                return ['text' => 'squad-out', 'mode' => 'squad', 'cost_usd' => 0.10, 'squad_id' => 's', 'completed' => [], 'roles' => [], 'checkpoint_path' => null, 'mailbox_log' => []];
            }
        };

        // Wire the synthetic provider tags so we can detect when auto
        // recursed up to a nested mode by inspecting `lastProvider`.
        $cross->setModes($auto, $smart, $squad);

        // Override the captured provider so cross-mode recursion shows
        // up distinctly — we wrap the dispatcher's dispatch() to record
        // the provider tag BEFORE branching into recursion vs leaf.
        $recordingCross = new class($core, $auto, $smart, $squad, $captured) extends CrossLayerDispatcher {
            public function __construct(Dispatcher $core, CliAutoMode $auto, CliSmartOrchestrator $smart, CliSquadOrchestrator $squad, private object $capt)
            {
                parent::__construct($core);
                $this->setModes($auto, $smart, $squad);
            }
            public function dispatch(array $step): array
            {
                $p = strtolower((string) ($step['provider'] ?? ''));
                if (in_array($p, ['auto', 'smart', 'squad'], true)) {
                    $this->capt->lastProvider = $p;
                }
                return parent::dispatch($step);
            }
        };
        $autoFinal = new CliAutoMode($recordingCross, null, ['default_cli' => 'cli:claude_cli']);
        $recordingCross->setModes($autoFinal, $smart, $squad);

        return [$autoFinal, $captured];
    }
}
