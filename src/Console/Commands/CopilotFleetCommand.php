<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\AgentRegistry;
use SuperAICore\Runner\CopilotFleetRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host-side fan-out across multiple Copilot sub-agents running the same
 * task in parallel. Conceptually equivalent to the interactive `/fleet`
 * command, but non-interactive so it can be scripted.
 *
 * Usage:
 *     copilot:fleet "refactor the auth layer" --agents reviewer,planner,tester
 *
 * Exit code: 0 if every agent succeeded, otherwise the highest child
 * exit code seen.
 */
#[AsCommand(
    name: 'copilot:fleet',
    description: 'Run the same task across multiple Copilot sub-agents in parallel'
)]
final class CopilotFleetCommand extends Command
{
    public function __construct(
        private readonly ?AgentRegistry $registry = null,
        private readonly ?CopilotFleetRunner $runner = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::REQUIRED, 'Task prompt to send to every agent')
            ->addOption('agents', 'a', InputOption::VALUE_REQUIRED, 'Comma-separated agent names')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Override model for all agents (e.g. gpt-5.4)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the resolved commands without executing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit aggregate results as a JSON array on stdout after streaming');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $task = (string) $input->getArgument('task');
        $agentsOpt = (string) ($input->getOption('agents') ?? '');
        $dryRun = (bool) $input->getOption('dry-run');
        $wantJson = (bool) $input->getOption('json');
        $model = $input->getOption('model') ?: null;

        $agentNames = array_values(array_filter(array_map('trim', explode(',', $agentsOpt))));
        if (!$agentNames) {
            $output->writeln('<error>--agents is required (comma-separated list)</error>');
            return Command::FAILURE;
        }

        $registry = $this->registry ?? new AgentRegistry();
        $agents = [];
        foreach ($agentNames as $name) {
            $agent = $registry->get($name);
            if (!$agent) {
                $output->writeln('<error>Agent not found: ' . $name . '</error>');
                return Command::FAILURE;
            }
            $agents[] = $agent;
        }

        $writer = fn(string $chunk) => $output->write($chunk);
        $runner = $this->runner ?? new CopilotFleetRunner(writer: $writer);

        $results = $runner->runFleet($task, $agents, $dryRun, $model);

        if ($dryRun) {
            return Command::SUCCESS;
        }

        if ($wantJson) {
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln('');
            $output->writeln('<info>Fleet summary:</info>');
            foreach ($results as $r) {
                $status = $r['exit_code'] === 0 ? '<info>ok</info>' : '<error>exit ' . $r['exit_code'] . '</error>';
                $output->writeln(sprintf(
                    '  %s  %s (%d out_tokens, %d premium_req, model=%s)',
                    $status,
                    $r['agent'],
                    $r['output_tokens'],
                    $r['premium_requests'],
                    $r['model'] ?? '?'
                ));
            }
        }

        $worst = 0;
        foreach ($results as $r) {
            if ($r['exit_code'] > $worst) $worst = $r['exit_code'];
        }
        return $worst === 0 ? Command::SUCCESS : $worst;
    }
}
