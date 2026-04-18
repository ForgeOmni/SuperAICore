<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\BackendRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'list-backends', description: 'List all registered backends and their availability')]
class ListBackendsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = new BackendRegistry();

        $table = new Table($output);
        $table->setHeaders(['Backend', 'Available', 'Notes']);
        foreach ($registry->all() as $backend) {
            $available = $backend->isAvailable() ? '<info>yes</info>' : '<comment>no</comment>';
            $notes = match ($backend->name()) {
                'claude_cli' => 'Requires `claude` in PATH',
                'codex_cli' => 'Requires `codex` in PATH',
                'gemini_cli' => 'Requires `gemini` in PATH',
                'copilot_cli' => 'Requires `copilot` in PATH (run `copilot login` first)',
                'anthropic_api' => 'Needs ANTHROPIC_API_KEY',
                'openai_api' => 'Needs OPENAI_API_KEY',
                'superagent' => 'Uses forgeomni/superagent in-process',
                default => '',
            };
            $table->addRow([$backend->name(), $available, $notes]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
