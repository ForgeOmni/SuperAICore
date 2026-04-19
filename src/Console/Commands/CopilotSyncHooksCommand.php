<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Sync\CopilotHookWriter;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sync host-app Claude-style hooks (PreToolUse / PostToolUse / SessionStart
 * / …) into Copilot's `~/.copilot/config.json`. Copilot accepts the
 * PascalCase event names verbatim and delivers the VS Code-compatible
 * snake_case payload, so translation is a pure file-placement operation.
 *
 * Source defaults to `<cwd>/.claude/settings.json` (the path Claude Code
 * itself reads). Use `--source` to point at a host-app-specific settings
 * file when that differs.
 *
 * Pass `--clear` to remove any previously-synced hooks block without
 * touching the user's other config.json keys.
 */
#[AsCommand(
    name: 'copilot:sync-hooks',
    description: 'Sync host hooks (.claude/settings.json hooks block) into Copilot config.json'
)]
final class CopilotSyncHooksCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source settings.json path (default: .claude/settings.json in cwd)')
            ->addOption('copilot-home', null, InputOption::VALUE_REQUIRED, 'Override Copilot config home (default: $XDG_CONFIG_HOME/copilot or $HOME/.copilot)')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Remove the previously-synced hooks block instead of writing one')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change without touching config.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $home = (string) ($input->getOption('copilot-home') ?? CopilotSyncCommand::defaultHome());
        $configJson = rtrim($home, '/') . '/config.json';
        $manifestPath = rtrim($home, '/') . '/.superaicore-manifest.json';

        $clear = (bool) $input->getOption('clear');
        $dryRun = (bool) $input->getOption('dry-run');

        $hooks = null;
        $sourceDisplay = '<clear>';
        if (!$clear) {
            $source = (string) ($input->getOption('source') ?? getcwd() . '/.claude/settings.json');
            $sourceDisplay = $source;
            $hooks = CopilotHookWriter::readFromSettings($source);
            if ($hooks === null) {
                $output->writeln("<comment>No hooks block found in {$source} — nothing to sync. Use --clear to remove an existing block.</comment>");
                return Command::SUCCESS;
            }
        }

        if ($dryRun) {
            $output->writeln("<info>[dry-run] would sync hooks from</info> {$sourceDisplay}");
            $output->writeln("<info>[dry-run] target:</info> {$configJson}");
            if ($hooks) {
                $output->writeln('<info>[dry-run] event keys:</info> ' . implode(', ', array_keys($hooks)));
            }
            return Command::SUCCESS;
        }

        $writer = new CopilotHookWriter($configJson, new Manifest($manifestPath));
        $result = $writer->sync($clear ? null : $hooks);

        match ($result['status']) {
            CopilotHookWriter::STATUS_WRITTEN =>
                $output->writeln("<info>+</info> hooks written to {$result['path']}"),
            CopilotHookWriter::STATUS_CLEARED =>
                $output->writeln("<info>-</info> hooks cleared from {$result['path']}"),
            CopilotHookWriter::STATUS_UNCHANGED =>
                $output->writeln("<comment>·</comment> hooks unchanged at {$result['path']}"),
            CopilotHookWriter::STATUS_USER_EDITED =>
                $output->writeln("<comment>!</comment> user-edited hooks at {$result['path']} — refusing to overwrite. Re-run with --clear first to reset."),
            default => $output->writeln("unknown status: {$result['status']}"),
        };

        return $result['status'] === CopilotHookWriter::STATUS_USER_EDITED
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
