<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\CliInstaller;
use SuperAICore\Services\CliStatusDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Surface the install + auth state of every engine CLI in one table.
 * Missing rows get a ready-to-paste `cli:install` hint.
 *
 * Works as the front door for `cli:install`: run `cli:status` first to
 * see what's missing, then `cli:install --all-missing` (or pick one).
 */
#[AsCommand(
    name: 'cli:status',
    description: 'Show install / version / auth status of engine CLIs (claude, codex, gemini, copilot, superagent)'
)]
final class CliStatusCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit the raw detector output as JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = CliStatusDetector::all();

        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Backend', 'Installed', 'Version', 'Auth', 'Install hint']);

        foreach ($rows as $backend => $row) {
            $installed = !empty($row['installed']);
            $installedCell = $installed ? '<info>yes</info>' : '<error>no</error>';
            $version = $row['version'] ?? '-';
            $auth = $this->formatAuth($backend, $row['auth'] ?? null, $installed);
            $hint = $installed ? '-' : ($this->installHintFor($backend) ?? 'n/a');

            $table->addRow([$backend, $installedCell, $version, $auth, $hint]);
        }

        $table->render();

        $missing = array_filter($rows, fn($r) => empty($r['installed']));
        // Superagent is a PHP SDK — don't nag about it from install flow.
        unset($missing['superagent']);
        if ($missing) {
            $names = implode(' ', array_keys($missing));
            $output->writeln('');
            $output->writeln("<comment>Missing:</comment> {$names}");
            $output->writeln("<comment>Install all missing:</comment> cli:install --all-missing");
        }

        return Command::SUCCESS;
    }

    private function formatAuth(string $backend, mixed $auth, bool $installed): string
    {
        if (!$installed) return '-';
        if ($backend === 'superagent') return 'SDK';
        if (!is_array($auth)) return '?';

        if (isset($auth['loggedIn'])) {
            return $auth['loggedIn']
                ? '<info>logged in</info>' . (isset($auth['method']) ? " ({$auth['method']})" : '')
                : '<comment>not logged in</comment>';
        }
        // Claude's `auth status --json` returns shape we don't normalize
        return json_encode($auth, JSON_UNESCAPED_SLASHES) ?: '?';
    }

    private function installHintFor(string $backend): ?string
    {
        if ($backend === 'superagent') return 'composer require forgeomni/superagent';
        return CliInstaller::installHint($backend);
    }
}
