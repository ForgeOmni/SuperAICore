<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use SuperAICore\Modes\CliAutoMode;
use SuperAICore\Modes\CrossLayerDispatcher;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore auto "<task>"` — runs `CliAutoMode`: scores the task,
 * decomposes it, and dispatches via the single CLI / smart / squad
 * path that fits the complexity.
 *
 * Same decision shape as SDK's `AutoMode\AutoModeAgent` (read the
 * same `TaskComplexity` thresholds), one layer up: picks across the
 * full CLI fleet plus the SDK fallback, not just one SDK provider.
 *
 * `--cli=cli:codex_cli` forces single-CLI mode with that backend.
 * `--mode=smart|squad|single` overrides the auto decision.
 */
#[AsCommand(name: 'auto', description: 'Cross-layer auto mode — picks single/smart/squad and the right backend')]
final class AutoCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('Cross-layer auto. Scores the task and picks single/smart/squad. Use --cli or --mode to override.')
            ->addArgument('task', InputArgument::REQUIRED, 'Free-form task')
            ->addOption('cli', null, InputOption::VALUE_REQUIRED, 'Force single-CLI mode with this backend tag (e.g. cli:claude_cli)')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Force mode: single | smart | squad')
            ->addOption('system', 's', InputOption::VALUE_REQUIRED, 'System prompt')
            ->addOption('max-depth', null, InputOption::VALUE_REQUIRED, 'Cross-mode max recursion depth (default 4)')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Total budget cap in USD; abort if exceeded')
            ->addOption('escalate-to', null, InputOption::VALUE_REQUIRED, 'Mode to escalate to on reviewer-loop failure (default: smart)')
            ->addOption('no-escalate', null, InputOption::VALUE_NONE, 'Disable auto-escalation on reviewer-loop max_retries')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $task = (string) $input->getArgument('task');

        $mode = $this->buildMode();
        $options = [];
        if ($cli = $input->getOption('cli')) {
            $options['cli'] = (string) $cli;
        }
        if ($m = $input->getOption('mode')) {
            $options['mode'] = (string) $m;
        }
        if ($s = $input->getOption('system')) {
            $options['system'] = (string) $s;
        }

        // Cross-mode policy from CLI flags (loosely coupled — applied
        // only when SDK Modes namespace is present; otherwise the
        // legacy single-mode path runs unchanged).
        $this->applyPolicyFlags($input);

        $result = $mode->run($task, $options);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $output->writeln($result['text'] ?? '');
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>· mode=%s · score=%.2f · cost=$%.4f</comment>',
                $result['mode'] ?? '?',
                (float) ($result['analysis']['score'] ?? 0.0),
                (float) ($result['cost_usd'] ?? 0.0),
            ));
        }
        return self::SUCCESS;
    }

    /**
     * Read `--max-depth` / `--budget` / `--escalate-to` /
     * `--no-escalate` and install a `CrossModePolicy` into the SDK's
     * `ModeRouterRegistry` for the lifetime of this command. Silently
     * no-ops when the SDK Modes namespace isn't on the classpath
     * (host pinned to a pre-cross-mode SDK build).
     */
    private function applyPolicyFlags(InputInterface $input): void
    {
        if (!class_exists(\SuperAgent\Modes\ModeRouterRegistry::class)) return;
        $hasFlag = $input->getOption('max-depth') !== null
                || $input->getOption('budget') !== null
                || $input->getOption('escalate-to') !== null
                || $input->getOption('no-escalate');
        if (!$hasFlag) return;

        $router = \SuperAgent\Modes\ModeRouterRegistry::get();
        if ($router === null) return;  // host bridge didn't install a router

        // Rebuild orchestrators with a fresh policy. We construct a
        // new ModeContext at run-time inside the orchestrator anyway,
        // but the policy needs to flow there — leave a note on the
        // metadata bag the orchestrator will read.
        $policy = new \SuperAgent\Modes\CrossModePolicy(
            maxDepth:              (int) ($input->getOption('max-depth') ?? 4),
            budgetCapUsd:          $input->getOption('budget') !== null ? (float) $input->getOption('budget') : null,
            autoEscalateOnFailure: ! $input->getOption('no-escalate'),
            escalateTo:            (string) ($input->getOption('escalate-to') ?? 'smart'),
        );
        // Stash on a well-known env var the orchestrators check for
        // — keeps the policy thread-local without forcing every
        // orchestrator ctor to accept yet another arg.
        $_SERVER['SUPERAICORE_MODE_POLICY'] = $policy;
    }

    private function buildMode(): CliAutoMode
    {
        if (function_exists('app')) {
            try { return app(CliAutoMode::class); } catch (\Throwable) {}
        }
        $backends = new BackendRegistry();
        $core = new Dispatcher($backends, new CostCalculator());
        $cross = new CrossLayerDispatcher($core);
        $mode = new CliAutoMode($cross);
        $cross->setModes(
            $mode,
            new \SuperAICore\Modes\CliSmartOrchestrator($cross),
            new \SuperAICore\Modes\CliSquadOrchestrator($cross),
        );
        return $mode;
    }
}
