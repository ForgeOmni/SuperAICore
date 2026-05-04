<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use SuperAICore\Plugins\InstallResult;
use SuperAICore\Plugins\MarketplaceInstaller;
use SuperAICore\Plugins\MarketplaceManifest;
use SuperAICore\Plugins\PluginInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Install one plugin or a curated subset of a marketplace.
 *
 * Three operating modes:
 *
 *   1. `--plugin=<dir>`            — install a single plugin dir
 *   2. `--marketplace=<file>`      — install every plugin in the marketplace
 *   3. `--marketplace=<file> --only=ruflo-sparc,ruflo-adr`
 *                                  — install a selected subset
 *
 * Default target is `~/.claude/plugins/`, which is the directory
 * `SkillRegistry` and Claude Code itself look at for user-scope plugins.
 * Override with `--target=<dir>`.
 */
#[AsCommand(
    name: 'plugins:install',
    description: 'Install plugins from a marketplace.json into the user-scope Claude plugins dir'
)]
final class PluginsInstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('marketplace', null, InputOption::VALUE_REQUIRED,
                'Path to marketplace.json (e.g. /path/to/ruflo/.claude-plugin/marketplace.json)')
            ->addOption('plugin', null, InputOption::VALUE_REQUIRED,
                'Single plugin directory (alternative to --marketplace)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED,
                'Comma-separated plugin names to limit a marketplace install (default: all)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED,
                'Target parent dir (default: ~/.claude/plugins)')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Overwrite even if installed files have been hand-edited')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Print what would change without touching disk');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $marketplaceArg = $input->getOption('marketplace');
        $pluginArg = $input->getOption('plugin');

        if (($marketplaceArg === null) === ($pluginArg === null)) {
            $output->writeln('<error>Specify exactly one of --marketplace or --plugin.</error>');
            return Command::INVALID;
        }

        $target = (string) ($input->getOption('target') ?? self::defaultTarget());
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $installer = new PluginInstaller(
            progress: function (string $msg) use ($output): void {
                if ($output->isVerbose()) $output->writeln($msg);
            }
        );

        if ($pluginArg !== null) {
            $result = $installer->install((string) $pluginArg, $target, $force, $dryRun);
            $this->renderResult($output, $result);
            return $result->ok() ? Command::SUCCESS : Command::FAILURE;
        }

        try {
            $market = MarketplaceManifest::fromJsonFile((string) $marketplaceArg);
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed to load marketplace: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $only = null;
        if ($onlyOpt = $input->getOption('only')) {
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $onlyOpt))));
        }

        $output->writeln("<info>marketplace:</info> {$market->name} ({$market->rootDir})");
        $output->writeln("<info>target:</info> {$target}");
        if ($only !== null) {
            $output->writeln('<info>only:</info> ' . implode(', ', $only));
        }
        if ($dryRun) {
            $output->writeln('<comment>[dry-run]</comment>');
        }

        $mi = new MarketplaceInstaller($installer);
        $report = $only !== null
            ? $mi->importSelected($market, $only, $target, $force, $dryRun)
            : $mi->importAll($market, $target, $force, $dryRun);

        $any_failed = false;
        foreach ($report as $row) {
            $this->renderResult($output, $row);
            if (!$row->ok()) $any_failed = true;
        }
        return $any_failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function renderResult(OutputInterface $output, InstallResult $r): void
    {
        $name = $r->name;
        $line = match ($r->status) {
            InstallResult::STATUS_INSTALLED   => "<info>+</info> {$name} installed → {$r->targetDir} ({$r->filesCopied} files)",
            InstallResult::STATUS_UPDATED     => "<info>~</info> {$name} updated → {$r->targetDir} ({$r->filesCopied} files)",
            InstallResult::STATUS_UNCHANGED   => "<comment>·</comment> {$name} unchanged at {$r->targetDir}",
            InstallResult::STATUS_USER_EDITED => "<comment>!</comment> {$name} user-edited at {$r->targetDir} — refusing to overwrite (re-run with --force to clobber)",
            InstallResult::STATUS_REMOVED     => "<info>-</info> {$name} removed",
            InstallResult::STATUS_FAILED      => "<error>x</error> {$name} failed: {$r->error}",
            default                           => "{$name}: {$r->status}",
        };
        $output->writeln($line);
    }

    public static function defaultTarget(): string
    {
        $home = (string) ($_SERVER['HOME'] ?? getenv('USERPROFILE') ?: getenv('HOME') ?: '.');
        return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'plugins';
    }
}
