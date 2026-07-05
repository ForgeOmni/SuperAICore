<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\RunStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore runs list|show <id>` — browse the structured run archive
 * that `send` / `resume` write (ai-dispatch parity for `runs list/show`).
 */
#[AsCommand(name: 'runs', description: 'List or inspect archived send/resume runs')]
class RunsCommand extends Command
{
    public function __construct(protected ?RunStore $runs = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list | show', 'list')
            ->addArgument('id', InputArgument::OPTIONAL, 'Run id (for show)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows for list', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output raw JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->runs ??= new RunStore();
        $action = (string) $input->getArgument('action');

        if ($action === 'show') {
            $id = (string) ($input->getArgument('id') ?? '');
            if ($id === '') {
                $output->writeln('<error>Usage: runs show <run-id></error>');
                return Command::FAILURE;
            }
            $run = $store->get($id);
            if ($run === null) {
                $output->writeln("<error>Run not found: {$id} (store: {$store->path()})</error>");
                return Command::FAILURE;
            }
            $output->writeln(json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        if ($action !== 'list') {
            $output->writeln("<error>Unknown action: {$action} (expected list|show)</error>");
            return Command::FAILURE;
        }

        $rows = $store->list(max(1, (int) $input->getOption('limit')));
        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln("No runs recorded yet (store: {$store->path()}).");
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['run id', 'when (UTC)', 'status', 'target', 'backend', 'model', 'session', 'degraded']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['run_id'],
                $row['recorded_at'] ?? '-',
                $row['status'] ?? '-',
                $row['requested_target'] ?? '-',
                $row['backend_used'] ?? '-',
                $row['model_used'] ?? '-',
                $row['session_id'] ?? '-',
                !empty($row['degraded']) ? 'yes' : 'no',
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}
