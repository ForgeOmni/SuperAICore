<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\SkillManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore skill:install-dispatch` — install the bundled
 * `superaicore-dispatch` SKILL into other agents' skill directories
 * (~/.claude/skills, ~/.codex/skills, ~/.gemini/skills) so Claude Code /
 * Codex / Gemini agents can delegate tasks INTO SuperAICore via
 * `superaicore send` (ai-dispatch parity for `npx skills add`).
 *
 * This is the reverse direction of `superaicore:sync-cli`, which pushes
 * the HOST's skills out to the engines SuperAICore drives.
 */
#[AsCommand(name: 'skill:install-dispatch', description: 'Install the superaicore-dispatch SKILL into Claude Code / Codex / Gemini agent skill dirs')]
class InstallDispatchSkillCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'agent',
            'a',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Target agent(s): claude | codex | gemini (repeatable)',
            ['claude'],
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceDir = dirname(__DIR__, 3) . '/resources/skills';
        $agents = array_values(array_unique((array) $input->getOption('agent')));

        $report = SkillManager::sync($sourceDir, $agents);

        $failed = false;
        foreach ($report as $row) {
            $ok = ($row['errors'] ?? []) === [];
            $failed = $failed || !$ok;
            $output->writeln(sprintf(
                '%s %s: synced=%d skipped=%d%s%s',
                $ok ? '<info>ok</info>' : '<error>error</error>',
                $row['backend'],
                $row['synced'] ?? 0,
                $row['skipped'] ?? 0,
                isset($row['target']) ? " → {$row['target']}" : '',
                ($row['errors'] ?? []) !== [] ? '  (' . implode('; ', $row['errors']) . ')' : '',
            ));
        }

        if (!$failed) {
            $output->writeln('');
            $output->writeln('Agents can now read the superaicore-dispatch SKILL and delegate via `superaicore send`.');
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
