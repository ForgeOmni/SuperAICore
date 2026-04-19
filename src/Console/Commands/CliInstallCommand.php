<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\CliInstaller;
use SuperAICore\Services\CliStatusDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * One-shot installer for engine CLIs (claude / codex / gemini / copilot).
 * Superagent is skipped — it's a Composer PHP SDK and lives in the host
 * app's composer.json, not at the CLI layer.
 *
 * Explicit by design: we never install as a side-effect of dispatch or
 * detection. The user runs `cli:install` themselves, confirms once
 * (unless `--yes`), and we shell out to the official package manager.
 *
 * Examples:
 *     cli:install copilot                   # install just copilot
 *     cli:install --all-missing             # install every CLI not detected
 *     cli:install codex --via=brew          # prefer Homebrew over npm
 *     cli:install --all-missing --yes       # CI-friendly, no prompt
 *     cli:install copilot --dry-run         # print the command, don't run
 */
#[AsCommand(
    name: 'cli:install',
    description: 'Install missing engine CLIs (claude, codex, gemini, copilot) via npm/brew/script'
)]
final class CliInstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('backend', InputArgument::OPTIONAL, 'claude|codex|gemini|copilot')
            ->addOption('all-missing', null, InputOption::VALUE_NONE, 'Install every CLI the detector reports as missing (ignores --backend)')
            ->addOption('via', null, InputOption::VALUE_REQUIRED, 'Install source: npm (default) | brew | script')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the install commands without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backend  = $input->getArgument('backend');
        $all      = (bool) $input->getOption('all-missing');
        $via      = $input->getOption('via');
        $yes      = (bool) $input->getOption('yes');
        $dryRun   = (bool) $input->getOption('dry-run');

        if (!$all && !$backend) {
            $output->writeln('<error>Specify a backend or pass --all-missing.</error>');
            $output->writeln('Available: ' . implode(', ', CliInstaller::INSTALLABLE_BACKENDS));
            return Command::FAILURE;
        }

        $targets = $all ? $this->resolveMissing($output) : [(string) $backend];
        $targets = array_values(array_filter($targets, fn($b) => in_array($b, CliInstaller::INSTALLABLE_BACKENDS, true)));

        if (!$targets) {
            $output->writeln('<info>Nothing to install — everything the installer knows about is already present.</info>');
            return Command::SUCCESS;
        }

        // Show the plan so `--yes` users still see what they're agreeing to.
        $output->writeln('<comment>Install plan:</comment>');
        foreach ($targets as $b) {
            $opt = CliInstaller::resolveSource($b, $via);
            if (!$opt) {
                $output->writeln("  <error>- {$b}: no install source '{$via}' known</error>");
                return Command::FAILURE;
            }
            $note = isset($opt['note']) ? " ({$opt['note']})" : '';
            $output->writeln("  - {$b}: " . implode(' ', $opt['argv']) . "{$note}");
        }

        if (!$dryRun && !$yes) {
            $helper = $this->getHelper('question');
            $q = new ConfirmationQuestion('Proceed? [y/N] ', false);
            if (!$helper->ask($input, $output, $q)) {
                $output->writeln('<comment>aborted.</comment>');
                return Command::FAILURE;
            }
        }

        $writer = fn(string $chunk) => $output->write($chunk);
        $worst = 0;
        foreach ($targets as $b) {
            $output->writeln('');
            $output->writeln("<info>== Installing {$b} ==</info>");
            $exit = CliInstaller::install($b, $via, $writer, $dryRun);
            if ($exit !== 0 && $exit > $worst) $worst = $exit;
        }

        if (!$dryRun) {
            $output->writeln('');
            $output->writeln('<info>Re-running detector to verify:</info>');
            foreach ($targets as $b) {
                $st = CliStatusDetector::detect($b);
                $ok = !empty($st['installed']);
                $marker = $ok ? '<info>✓</info>' : '<error>✗</error>';
                $ver = $st['version'] ?? '-';
                $output->writeln("  {$marker} {$b} ({$ver})");
            }
        }

        return $worst === 0 ? Command::SUCCESS : $worst;
    }

    /** @return array<int,string> */
    private function resolveMissing(OutputInterface $output): array
    {
        $missing = [];
        foreach (CliInstaller::INSTALLABLE_BACKENDS as $b) {
            $st = CliStatusDetector::detect($b);
            if (empty($st['installed'])) {
                $missing[] = $b;
            }
        }
        return $missing;
    }
}
