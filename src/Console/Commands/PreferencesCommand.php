<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Support\DispatchPreferences;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore preferences init|path|show` — the natural-language
 * scenario-preference file calling agents read BEFORE picking a `send`
 * target (ai-dispatch parity for `~/.ai-dispatch/preferences.md`).
 */
#[AsCommand(name: 'preferences', description: 'Manage the dispatch preferences file agents read before picking a send target')]
class PreferencesCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'show | path | init', 'show');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $path = DispatchPreferences::path();

        switch ($action) {
            case 'path':
                $output->writeln($path);
                return Command::SUCCESS;

            case 'init':
                if (DispatchPreferences::exists()) {
                    $output->writeln("Already exists: {$path}");
                    return Command::SUCCESS;
                }
                if (!DispatchPreferences::init()) {
                    $output->writeln("<error>Could not write {$path}</error>");
                    return Command::FAILURE;
                }
                $output->writeln("Created {$path} — edit it to record your scenario/model preferences.");
                return Command::SUCCESS;

            case 'show':
                $content = DispatchPreferences::read();
                if ($content === null) {
                    $output->writeln("No preferences file at {$path} — run `superaicore preferences init` to create one.");
                    return Command::SUCCESS;
                }
                $output->write($content);
                return Command::SUCCESS;

            default:
                $output->writeln("<error>Unknown action: {$action} (expected show|path|init)</error>");
                return Command::FAILURE;
        }
    }
}
