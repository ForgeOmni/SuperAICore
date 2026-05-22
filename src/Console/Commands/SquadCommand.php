<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use SuperAICore\Modes\CliSquadOrchestrator;
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
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `superaicore squad "<task>"` — runs `CliSquadOrchestrator`: SDK
 * 1.0.0 `PeerOrchestrator` + cross-layer dispatcher. Each role's
 * provider tag chooses how that step actually runs:
 *
 *   cli:claude_cli      → host's Claude CLI backend
 *   cli:codex_cli       → host's Codex CLI backend
 *   sdk:anthropic       → SuperAgent SDK directly
 *   auto / smart / squad → recurse into the matching CLI-layer mode
 *
 * `--sdk` shells out to vendor `superagent auto --squad` (pure SDK
 * squad mode) for callers who don't want CLI-layer routing.
 */
#[AsCommand(name: 'squad', description: 'Run cross-CLI Squad multi-agent (CLI layer) or passthrough to SDK')]
final class SquadCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('CLI-layer squad orchestrator. Uses SDK PeerOrchestrator with a cross-layer dispatcher so each role can land on a CLI backend, SDK provider, or a nested mode.')
            ->addArgument('task', InputArgument::REQUIRED, 'Free-form task to orchestrate')
            ->addOption('sdk', null, InputOption::VALUE_NONE, 'Passthrough to vendor `superagent auto --squad`')
            ->addOption('tier-map', null, InputOption::VALUE_REQUIRED, 'JSON tier-map: band → {provider, model}')
            ->addOption('checkpoint-dir', null, InputOption::VALUE_REQUIRED, 'Directory for per-step JSON checkpoints')
            ->addOption('max-cost', null, InputOption::VALUE_REQUIRED, 'Cost cap with downshift at 80%')
            ->addOption('max-depth', null, InputOption::VALUE_REQUIRED, 'Cross-mode max recursion depth (default 4)')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Total budget cap in USD; abort if exceeded')
            ->addOption('escalate-to', null, InputOption::VALUE_REQUIRED, 'Mode to escalate to on reviewer-loop failure (default: smart)')
            ->addOption('no-escalate', null, InputOption::VALUE_NONE, 'Disable auto-escalation on reviewer-loop max_retries')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope')
            ->addOption('no-skills', null, InputOption::VALUE_NONE, 'Disable Skill auto-discovery for this run (Pi-style clean-mode)')
            ->addOption('no-session', null, InputOption::VALUE_NONE, 'Skip session persistence / harness session for this run')
            ->addOption('combo', null, InputOption::VALUE_REQUIRED, '9Router-borrowed: named routing combo (resolved via ai_routing_combos). Overrides --tier-map.')
            ->addOption('caveman', null, InputOption::VALUE_NONE, '9Router-borrowed: inject terse-prose system prompt to reduce output tokens 30-65%.')
            ->addOption('binary', null, InputOption::VALUE_REQUIRED, 'Path to vendor superagent binary (only with --sdk)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $task = (string) $input->getArgument('task');

        if ($input->getOption('sdk')) {
            return $this->runSdkPassthrough($input, $output, $task);
        }

        $orchestrator = $this->buildOrchestrator();
        $options = [];
        if ($t = $input->getOption('tier-map')) {
            $decoded = json_decode((string) $t, true);
            if (is_array($decoded)) $options['tier_map'] = $decoded;
        }
        if ($d = $input->getOption('checkpoint-dir')) {
            $options['checkpoint_dir'] = (string) $d;
        }
        if ($c = $input->getOption('max-cost')) {
            $options['max_cost_usd'] = (float) $c;
        }
        if ($comboName = $input->getOption('combo')) {
            $entries = class_exists(\SuperAICore\Models\AiRoutingCombo::class)
                ? \SuperAICore\Models\AiRoutingCombo::resolveEntries((string) $comboName)
                : [];
            if ($entries === []) {
                $output->writeln("<error>Combo '{$comboName}' not found or has no entries.</error>");
                return self::FAILURE;
            }
            $options['combo'] = (string) $comboName;
            $options['combo_entries'] = $entries;
        }
        if ($input->getOption('caveman')) {
            $options['caveman'] = true;
            $options['per_call_options']['caveman'] = true;
        }
        if ($input->getOption('no-skills')) {
            $options['skills_disabled'] = true;
            $options['per_call_options']['skills_disabled'] = true;
        }
        if ($input->getOption('no-session')) {
            $options['session_disabled'] = true;
            $options['per_call_options']['session_disabled'] = true;
        }

        $result = $orchestrator->run($task, $options);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $output->writeln($result['text'] ?? '');
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>· mode=%s · squad_id=%s · completed=%d · cost=$%.4f%s</comment>',
                $result['mode'] ?? '?',
                $result['squad_id'] ?? '',
                count($result['completed'] ?? []),
                (float) ($result['cost_usd'] ?? 0.0),
                !empty($result['has_cross_mode']) ? ' · cross_mode=on' : '',
            ));
        }
        return self::SUCCESS;
    }

    private function buildOrchestrator(): CliSquadOrchestrator
    {
        if (function_exists('app')) {
            try { return app(CliSquadOrchestrator::class); } catch (\Throwable) {}
        }
        $backends = new BackendRegistry();
        $core = new Dispatcher($backends, new CostCalculator());
        $cross = new CrossLayerDispatcher($core);
        $orch = new CliSquadOrchestrator($cross);
        $cross->setModes(
            new \SuperAICore\Modes\CliAutoMode($cross),
            new \SuperAICore\Modes\CliSmartOrchestrator($cross),
            $orch,
        );
        return $orch;
    }

    private function runSdkPassthrough(InputInterface $input, OutputInterface $output, string $task): int
    {
        $binary = (string) ($input->getOption('binary') ?? $this->locateSuperagentBinary());
        if ($binary === '') {
            $output->writeln('<error>SDK squad requires the `superagent` binary.</error>');
            return self::FAILURE;
        }
        $args = [(new PhpExecutableFinder())->find() ?: 'php', $binary, 'auto', '--squad', $task];
        if ($c = $input->getOption('max-cost')) { $args[] = '--max-cost'; $args[] = (string) $c; }
        if ($input->getOption('no-skills'))      { $args[] = '--no-skills'; }
        if ($input->getOption('no-session'))     { $args[] = '--no-session'; }

        $proc = new Process($args);
        $proc->setTimeout(null);
        $proc->setTty(Process::isTtySupported());
        $proc->run(function ($_, $buf) use ($output) { $output->write($buf); });
        return $proc->getExitCode() ?? self::FAILURE;
    }

    private function locateSuperagentBinary(): string
    {
        $candidates = [
            __DIR__ . '/../../../vendor/forgeomni/superagent/bin/superagent',
            __DIR__ . '/../../../../../forgeomni/superagent/bin/superagent',
            __DIR__ . '/../../../../forgeomni/superagent/bin/superagent',
        ];
        foreach ($candidates as $c) { if (is_file($c)) return $c; }
        return '';
    }
}
