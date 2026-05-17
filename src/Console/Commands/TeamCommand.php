<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use SuperAgent\Squad\TeamRegistry;
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

/**
 * `superaicore team <subcommand>` — drives the SDK's `TeamRegistry`
 * (single source of truth for squad team YAMLs).
 *
 * Subcommands:
 *
 *   superaicore team list                       — every name in the registry
 *   superaicore team show <name>                — description + step graph
 *   superaicore team run  <name> "<task>"       — execute the team
 *
 * The registry merges SDK-bundled teams with any host-registered
 * directories (set via `super-ai-core.squad_team_dirs` config). Hosts
 * that want to ship their own teams alongside the SDK's library drop
 * them in a registered directory.
 *
 * Run dispatch goes through `CliSquadOrchestrator`, which means every
 * tier-map provider tag (`cli:…`, `sdk:…`, `auto`, `smart`, `squad`)
 * works inside team runs too — a YAML can mix CLI and SDK roles.
 */
#[AsCommand(name: 'team', description: 'List / show / run a bundled or registered squad team')]
final class TeamCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
Subcommands:

  team list                          List every team name in the registry.
  team show <name>                   Print the team's description, steps,
                                     loops, and tier map.
  team run  <name> [task]            Execute the team. The task argument
                                     becomes the {{task}} substitution in
                                     the team's prompts.

Examples:

  superaicore team list
  superaicore team show code-review-loop
  superaicore team run  code-review-loop "Add idempotency to the order endpoint"
HELP)
            ->addArgument('subcommand', InputArgument::REQUIRED, 'list | show | run')
            ->addArgument('name', InputArgument::OPTIONAL, 'Team name (for show/run)')
            ->addArgument('task', InputArgument::OPTIONAL, 'Free-form task (for run)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON envelope')
            ->addOption('checkpoint-dir', null, InputOption::VALUE_REQUIRED, 'Per-step checkpoint directory (run only)')
            ->addOption('max-cost', null, InputOption::VALUE_REQUIRED, 'Cost cap with downshift at 80% (run only)')
            ->addOption('max-depth', null, InputOption::VALUE_REQUIRED, 'Cross-mode max recursion depth (default 4)')
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Total budget cap in USD across nested cross-mode runs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sub = (string) $input->getArgument('subcommand');
        $registry = $this->buildRegistry();

        return match ($sub) {
            'list' => $this->runList($input, $output, $registry),
            'show' => $this->runShow($input, $output, $registry),
            'run'  => $this->runRun($input, $output, $registry),
            default => $this->unknownSubcommand($output, $sub),
        };
    }

    private function runList(InputInterface $input, OutputInterface $output, TeamRegistry $r): int
    {
        $names = $r->list();
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($names, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        if ($names === []) {
            $output->writeln('<comment>No teams registered.</comment>');
            return self::SUCCESS;
        }
        $output->writeln(sprintf('<info>%d team(s) available:</info>', count($names)));
        foreach ($names as $n) {
            $origin = $r->origin($n);
            $tag = $origin !== null ? '<comment>[' . $origin['tier'] . ']</comment>' : '';
            $output->writeln("  {$n} {$tag}");
        }
        return self::SUCCESS;
    }

    private function runShow(InputInterface $input, OutputInterface $output, TeamRegistry $r): int
    {
        $name = (string) $input->getArgument('name');
        if ($name === '') {
            $output->writeln('<error>team show <name> — missing team name</error>');
            return self::FAILURE;
        }
        $plan = $r->load($name);
        if ($plan === null) {
            $output->writeln(sprintf('<error>Team not found: %s</error>', $name));
            return self::FAILURE;
        }
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($plan->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        $output->writeln("<info>name:</info>        {$plan->name}");
        $output->writeln("<info>description:</info> " . ($plan->description ?? '(none)'));
        $output->writeln("<info>steps:</info>");
        foreach ($plan->subTasks as $st) {
            $deps = $st->dependsOn === [] ? '' : ' depends_on=[' . implode(',', $st->dependsOn) . ']';
            $review = $st->requiresReview ? ' <comment>pause_after</comment>' : '';
            $parallel = $st->parallelGroup ? " parallel_group={$st->parallelGroup}" : '';
            $output->writeln("  - {$st->name} (tier={$st->difficulty->value}){$deps}{$parallel}{$review}");
        }
        if ($plan->loops !== []) {
            $output->writeln("<info>loops:</info>");
            foreach ($plan->loops as $loop) {
                $output->writeln("  - {$loop->writer} ⇄ {$loop->reviewer} (max_retries={$loop->maxRetries})");
            }
        }
        if ($plan->tierMap !== []) {
            $output->writeln("<info>tier_map:</info>");
            foreach ($plan->tierMap as $band => $entry) {
                $output->writeln("  {$band}: {$entry['provider']} / {$entry['model']}");
            }
        }
        $origin = $r->origin($name);
        if ($origin !== null) {
            $output->writeln(sprintf('<comment>· origin: %s (%s)</comment>', $origin['tier'], $origin['source']));
        }
        return self::SUCCESS;
    }

    private function runRun(InputInterface $input, OutputInterface $output, TeamRegistry $r): int
    {
        $name = (string) $input->getArgument('name');
        $task = (string) $input->getArgument('task');
        if ($name === '') {
            $output->writeln('<error>team run <name> "<task>" — missing team name</error>');
            return self::FAILURE;
        }
        $plan = $r->load($name);
        if ($plan === null) {
            $output->writeln(sprintf('<error>Team not found: %s</error>', $name));
            return self::FAILURE;
        }
        $orchestrator = $this->buildOrchestrator();
        $options = ['plan' => $plan];
        if ($d = $input->getOption('checkpoint-dir')) {
            $options['checkpoint_dir'] = (string) $d;
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
                '<comment>· team=%s · squad_id=%s · completed=%d · cost=$%.4f%s</comment>',
                $name,
                $result['squad_id'] ?? '',
                count($result['completed'] ?? []),
                (float) ($result['cost_usd'] ?? 0.0),
                !empty($result['has_cross_mode']) ? ' · cross_mode=on' : '',
            ));
        }
        return self::SUCCESS;
    }

    private function unknownSubcommand(OutputInterface $output, string $sub): int
    {
        $output->writeln(sprintf('<error>Unknown subcommand: %s — use list | show | run</error>', $sub));
        return self::INVALID;
    }

    private function buildRegistry(): TeamRegistry
    {
        // Container path — picks up host-registered directories.
        if (function_exists('app')) {
            try { return app(TeamRegistry::class); } catch (\Throwable) {}
        }
        return new TeamRegistry();
    }

    private function buildOrchestrator(): CliSquadOrchestrator
    {
        if (function_exists('app')) {
            try { return app(CliSquadOrchestrator::class); } catch (\Throwable) {}
        }
        $core = new Dispatcher(new BackendRegistry(), new CostCalculator());
        $cross = new CrossLayerDispatcher($core);
        $orch = new CliSquadOrchestrator($cross);
        $cross->setModes(
            new \SuperAICore\Modes\CliAutoMode($cross),
            new \SuperAICore\Modes\CliSmartOrchestrator($cross),
            $orch,
        );
        return $orch;
    }
}
