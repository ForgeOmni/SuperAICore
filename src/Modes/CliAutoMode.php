<?php

declare(strict_types=1);

namespace SuperAICore\Modes;

use Psr\Log\LoggerInterface;
use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAgent\SmartContext\TaskComplexity;
use SuperAgent\Squad\TaskDecomposer;

/**
 * CLI-layer Auto mode — the host-side analogue to SDK's
 * `AutoMode\AutoModeAgent`. Same decision shape, different decision
 * domain:
 *
 *   SDK auto:  one provider, picks single-agent vs multi-agent.
 *   CLI auto:  many CLI backends, picks single-CLI vs spread-across-CLIs.
 *
 * Decision tree on every `run()`:
 *
 *   1. Score the prompt with SDK's `TaskComplexity` (same scoring
 *      every other layer uses — keeps user mental model consistent).
 *   2. Decompose with `TaskDecomposer`. Count distinct difficulty
 *      bands.
 *   3. Routing:
 *        - score < 0.4 OR 1 subtask              → single CLI (default)
 *        - score >= 0.7 AND ≥ 2 bands            → escalate to `squad`
 *        - score in [0.4, 0.7) OR ≥ 2 subtasks   → `smart` (fan-out
 *                                                   across CLIs by score)
 *
 * The actual dispatch happens through `CrossLayerDispatcher`, so a
 * single-CLI run honours `cli:<name>` overrides while a squad/smart
 * branch can mix CLI and SDK roles freely.
 *
 * Override knobs (per call):
 *   - cli:           force a specific CLI backend (single mode)
 *   - mode:          force 'single' | 'smart' | 'squad'
 *   - default_cli:   tag to use when no per-task CLI was chosen
 *                    (default: `cli:claude_cli`)
 *
 * Override knobs (config `super-ai-core.cli_auto`):
 *   - default_cli, smart_threshold (0.4), squad_threshold (0.7),
 *     prefer_squad (true)
 */
class CliAutoMode implements ModeOrchestrator
{
    public function __construct(
        private CrossLayerDispatcher $dispatcher,
        private ?LoggerInterface $logger = null,
        /** @var array{default_cli?:string, smart_threshold?:float, squad_threshold?:float, prefer_squad?:bool} */
        private array $config = [],
    ) {}

    public function modeName(): string
    {
        return 'auto';
    }

    /**
     * Cross-mode entry point. Adapts the legacy `run($prompt, $options)`
     * signature to the `ModeOrchestrator` contract. The `ModeContext`
     * is currently used for cost-ledger accumulation; future versions
     * may surface blackboard reads (e.g. "prior researcher findings").
     *
     * @param array<string,mixed> $options
     */
    public function execute(string $task, ModeContext $context, array $options = []): ModeResult
    {
        $legacy = $this->run($task, $options);
        $cost = (float) ($legacy['cost_usd'] ?? 0.0);
        $context->costLedger->record('auto', $cost);
        return new ModeResult(
            text:    (string) ($legacy['text'] ?? ''),
            costUsd: $cost,
            mode:    'auto',
            trace:   $context->modeStack,
            modeSpecific: [
                'analysis' => $legacy['analysis'] ?? null,
                'signals'  => $legacy['signals'] ?? [],
                'picked'   => $legacy['mode'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string,mixed> $options
     * @return array{text:string, mode:string, cost_usd:float, signals:array, analysis:array}
     */
    public function run(string $prompt, array $options = []): array
    {
        $forcedMode = $options['mode'] ?? null;
        $forcedCli  = $options['cli']  ?? null;

        $analysis = TaskComplexity::analyze($prompt);
        $subTasks = (new TaskDecomposer())->decompose($prompt);

        $bands = [];
        foreach ($subTasks as $s) { $bands[$s->difficulty->value] = true; }

        $smartTh = (float) ($this->config['smart_threshold'] ?? 0.4);
        $squadTh = (float) ($this->config['squad_threshold'] ?? 0.7);
        $preferSquad = (bool) ($this->config['prefer_squad'] ?? true);

        $mode = 'single';
        if ($forcedMode !== null) {
            $mode = (string) $forcedMode;
        } elseif ($forcedCli !== null) {
            $mode = 'single';
        } elseif ($analysis->score >= $squadTh && count($bands) >= 2 && $preferSquad) {
            $mode = 'squad';
        } elseif ($analysis->score >= $smartTh || count($subTasks) >= 2) {
            $mode = 'smart';
        }

        if ($this->logger) {
            $this->logger->info('CliAutoMode: picked mode', [
                'mode'           => $mode,
                'score'          => $analysis->score,
                'signals'        => $analysis->signals,
                'subtask_count'  => count($subTasks),
                'distinct_bands' => count($bands),
            ]);
        }

        $base = [
            'text'     => '',
            'mode'     => $mode,
            'cost_usd' => 0.0,
            'signals'  => $analysis->signals,
            'analysis' => [
                'score'         => $analysis->score,
                'strategy'      => $analysis->strategy->value ?? null,
                'subtask_count' => count($subTasks),
                'bands'         => array_keys($bands),
            ],
        ];

        // single-mode delegates straight to the cross-layer dispatcher.
        // smart/squad routes back into the cross-layer dispatcher via
        // its 'smart' / 'squad' synthetic provider tags — keeps one
        // entry point so cost / metadata flow stays uniform.
        if ($mode === 'single') {
            $cli = (string) ($forcedCli ?? $this->config['default_cli'] ?? 'cli:claude_cli');
            $r = $this->dispatcher->dispatch([
                'provider' => $cli,
                'prompt'   => $prompt,
                'system'   => $options['system'] ?? null,
                'options'  => $options,
            ]);
            return array_merge($base, [
                'text'     => $r['output'] ?? '',
                'cost_usd' => (float) ($r['cost_usd'] ?? 0.0),
            ]);
        }

        // Recurse via the synthetic provider tag. The dispatcher's
        // setModes() binding routes 'smart' / 'squad' back into the
        // matching orchestrator without us re-instantiating it.
        $r = $this->dispatcher->dispatch([
            'provider' => $mode,
            'prompt'   => $prompt,
            'options'  => $options,
        ]);
        return array_merge($base, [
            'text'     => $r['output'] ?? '',
            'cost_usd' => (float) ($r['cost_usd'] ?? 0.0),
        ]);
    }
}
