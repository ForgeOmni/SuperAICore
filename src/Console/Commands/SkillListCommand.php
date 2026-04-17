<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\SkillRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skill:list',
    description: 'List skills discovered from project/plugin/user sources'
)]
final class SkillListCommand extends Command
{
    public function __construct(private readonly ?SkillRegistry $registry = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table|json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new SkillRegistry();
        $skills = $registry->all();
        $format = $input->getOption('format');

        if ($format === 'json') {
            $payload = [];
            foreach ($skills as $s) {
                $payload[] = [
                    'name' => $s->name,
                    'description' => $s->description,
                    'source' => $s->source,
                    'path' => $s->path,
                ];
            }
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        if (!$skills) {
            $output->writeln('<comment>No skills found.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Source', 'Description']);
        foreach ($skills as $s) {
            $table->addRow([$s->name, $s->source, $this->truncate($s->description ?? '', 80)]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}
