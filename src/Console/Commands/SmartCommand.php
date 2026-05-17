<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use SuperAICore\Modes\CliSmartOrchestrator;
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
 * `superaicore smart "<task>"` — runs the CLI-layer
 * `CliSmartOrchestrator`: decompose → route each subtask to a CLI
 * backend (or sdk:/auto/squad) by difficulty → merge.
 *
 * For the SDK-internal smart mode (eval-score driven, runs against
 * SDK LLMProvider), pass `--sdk` and we'll shell out to vendor's
 * `superagent smart` instead.
 *
 * Both layers share the same `TaskDecomposer` + `TaskComplexity`
 * scoring so a prompt's difficulty score is identical regardless of
 * which layer drives it.
 */
#[AsCommand(name: 'smart', description: 'Run cross-CLI smart orchestration (CLI layer) or passthrough to SDK')]
final class SmartCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('CLI-layer smart orchestrator. Decomposes the task and routes each subtask to a CLI backend by difficulty. Pass --sdk to use the SDK-internal smart mode instead.')
            ->addArgument('task', InputArgument::REQUIRED, 'Free-form task to orchestrate')
            ->addOption('sdk', null, InputOption::VALUE_NONE, 'Passthrough to vendor `superagent smart` (eval-score driven)')
            ->addOption('routing', null, InputOption::VALUE_REQUIRED, 'JSON tier-map override, e.g. {"hard":"cli:codex_cli"}')
            ->addOption('merge', null, InputOption::VALUE_REQUIRED, 'Provider tag for the merge step (default: same as expert tier)')
            ->addOption('max-cost', null, InputOption::VALUE_REQUIRED, 'Abort when running cost exceeds this USD cap')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope instead of plain text')
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
        if ($r = $input->getOption('routing')) {
            $decoded = json_decode((string) $r, true);
            if (is_array($decoded)) $options['routing'] = $decoded;
        }
        if ($m = $input->getOption('merge')) {
            $options['merge_provider'] = (string) $m;
        }
        if ($c = $input->getOption('max-cost')) {
            $options['max_cost_usd'] = (float) $c;
        }

        $result = $orchestrator->run($task, $options);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $output->writeln($result['text'] ?? '');
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>· mode=%s · subtasks=%d · cost=$%.4f</comment>',
                $result['mode'] ?? '?',
                count($result['subtask_results'] ?? []),
                (float) ($result['cost_usd'] ?? 0.0),
            ));
        }
        return self::SUCCESS;
    }

    private function buildOrchestrator(): CliSmartOrchestrator
    {
        if (function_exists('app')) {
            try { return app(CliSmartOrchestrator::class); } catch (\Throwable) {}
        }
        $backends = new BackendRegistry();
        $core = new Dispatcher($backends, new CostCalculator());
        $cross = new CrossLayerDispatcher($core);
        $orch = new CliSmartOrchestrator($cross);
        // Wire setModes so `merge_provider: smart/squad` recursion works even
        // when running outside a Laravel container.
        $cross->setModes(
            new \SuperAICore\Modes\CliAutoMode($cross),
            $orch,
            new \SuperAICore\Modes\CliSquadOrchestrator($cross),
        );
        return $orch;
    }

    private function runSdkPassthrough(InputInterface $input, OutputInterface $output, string $task): int
    {
        $binary = (string) ($input->getOption('binary') ?? $this->locateSuperagentBinary());
        if ($binary === '') {
            $output->writeln('<error>SDK smart requires the `superagent` binary. Pass --binary=… or install forgeomni/superagent.</error>');
            return self::FAILURE;
        }
        $args = [(new PhpExecutableFinder())->find() ?: 'php', $binary, 'smart', $task];
        if ($c = $input->getOption('max-cost')) { $args[] = '--max-cost'; $args[] = (string) $c; }
        if ($input->getOption('json'))           { $args[] = '--json'; }

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
