<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\AgentRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'agent:list',
    description: 'List Claude sub-agents discovered from project/user sources'
)]
final class AgentListCommand extends Command
{
    public function __construct(private readonly ?AgentRegistry $registry = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table|json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = ($this->registry ?? new AgentRegistry())->all();
        $format = $input->getOption('format');

        if ($format === 'json') {
            $payload = [];
            foreach ($agents as $a) {
                $payload[] = [
                    'name'          => $a->name,
                    'description'   => $a->description,
                    'source'        => $a->source,
                    'model'         => $a->model,
                    'allowed_tools' => $a->allowedTools,
                    'path'          => $a->path,
                ];
            }
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        if (!$agents) {
            $output->writeln('<comment>No agents found.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Source', 'Model', 'Description']);
        foreach ($agents as $a) {
            $table->addRow([
                $a->name,
                $a->source,
                $a->model ?? '—',
                $this->truncate($a->description ?? '', 60),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}
