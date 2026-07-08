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
 * (~/.claude/skills, ~/.codex/skills, ~/.gemini/skills, ~/.grok/skills,
 * ~/.cursor/skills-cursor, ~/.qwen/skills) so agents running in those
 * CLIs can delegate tasks INTO SuperAICore via `superaicore send`
 * (ai-dispatch parity for `npx skills add`).
 *
 * Defaults to claude + codex (the two agents ai-dispatch itself targets);
 * `--agent all` covers every known skill dir, `--uninstall` reverses a
 * prior install without touching the user's own skills.
 *
 * This is the reverse direction of `superaicore:sync-cli`, which pushes
 * the HOST's skills out to the engines SuperAICore drives.
 */
#[AsCommand(name: 'skill:install-dispatch', description: 'Install the superaicore-dispatch SKILL into Claude Code / Codex / Gemini / Grok / Cursor / Qwen agent skill dirs')]
class InstallDispatchSkillCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'agent',
                'a',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Target agent(s): ' . implode(' | ', SkillManager::knownBackends()) . ' | all (repeatable)',
                ['claude', 'codex'],
            )
            ->addOption(
                'uninstall',
                null,
                InputOption::VALUE_NONE,
                'Remove a previously installed superaicore-dispatch SKILL instead',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceDir = dirname(__DIR__, 3) . '/resources/skills';
        $agents = array_values(array_unique((array) $input->getOption('agent')));
        if (in_array('all', $agents, true)) {
            $agents = SkillManager::knownBackends();
        }

        if ($input->getOption('uninstall')) {
            foreach (SkillManager::unsync($sourceDir, $agents) as $row) {
                $ok = ($row['errors'] ?? []) === [];
                $output->writeln(sprintf(
                    '%s %s: removed=%d%s%s',
                    $ok ? '<info>ok</info>' : '<error>error</error>',
                    $row['backend'],
                    $row['removed'] ?? 0,
                    isset($row['target']) ? " → {$row['target']}" : '',
                    ($row['errors'] ?? []) !== [] ? '  (' . implode('; ', $row['errors']) . ')' : '',
                ));
            }
            return Command::SUCCESS;
        }

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
