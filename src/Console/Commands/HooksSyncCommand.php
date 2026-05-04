<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Sync\ClaudeHookWriter;
use SuperAICore\Sync\CopilotHookWriter;
use SuperAICore\Sync\HookFanoutService;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Single-source hooks fanout for every CLI engine that natively
 * understands Claude-style hooks.
 *
 * Source resolution (first hit wins):
 *   1. `--source` if given
 *   2. `<cwd>/.superaicore/hooks.json` (host-managed)
 *   3. `<cwd>/.claude/settings.json` `hooks` block
 *
 * Targets are auto-detected from the registered writers. Today: Claude
 * (`<cwd>/.claude/settings.json`) and Copilot (`~/.copilot/config.json`).
 * Use `--engine=copilot,claude` to limit the fanout.
 *
 * Exit code: 0 unless any engine reports `user_edited` (rsync-style — we
 * refuse to clobber a hand-edit, host has to clear first).
 *
 * Sibling of `copilot:sync-hooks`, which still exists for hosts that only
 * want to drive Copilot. This command supersedes it for multi-engine setups.
 */
#[AsCommand(
    name: 'hooks:sync',
    description: 'Fan one Claude-style hooks manifest out to every supported CLI engine'
)]
final class HooksSyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED,
                'Source hooks file (default: .superaicore/hooks.json or .claude/settings.json in cwd)')
            ->addOption('engine', null, InputOption::VALUE_REQUIRED,
                'Comma-separated engine keys to limit the fanout to (default: all registered)')
            ->addOption('claude-settings', null, InputOption::VALUE_REQUIRED,
                'Override Claude target settings.json (default: <cwd>/.claude/settings.json)')
            ->addOption('copilot-home', null, InputOption::VALUE_REQUIRED,
                'Override Copilot config home (default: $XDG_CONFIG_HOME/copilot or $HOME/.copilot)')
            ->addOption('clear', null, InputOption::VALUE_NONE,
                'Remove previously-synced hooks blocks instead of writing')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Print what would change without touching disk');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = (string) (getcwd() ?: '.');
        $clear = (bool) $input->getOption('clear');
        $dryRun = (bool) $input->getOption('dry-run');

        $only = null;
        if ($engineOpt = $input->getOption('engine')) {
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $engineOpt))));
        }

        $hooks = null;
        $sourceDisplay = '<clear>';
        if (!$clear) {
            [$resolved, $hooks] = HookFanoutService::resolveSource(
                $input->getOption('source'),
                $cwd,
            );
            if ($resolved === null || $hooks === null) {
                $output->writeln('<comment>No hooks source found. Tried:</comment>');
                $output->writeln("  - {$cwd}/.superaicore/hooks.json");
                $output->writeln("  - {$cwd}/.claude/settings.json");
                $output->writeln('<comment>Use --source to point elsewhere, or --clear to remove previously-synced blocks.</comment>');
                return Command::SUCCESS;
            }
            $sourceDisplay = $resolved;
        }

        $fanout = $this->buildFanout($input, $cwd);

        if ($dryRun) {
            $output->writeln("<info>[dry-run] source:</info> {$sourceDisplay}");
            $output->writeln('<info>[dry-run] engines:</info> ' . implode(', ', $only ?? $fanout->engines()));
            if ($hooks) {
                $output->writeln('<info>[dry-run] event keys:</info> ' . implode(', ', array_keys($hooks)));
            }
            return Command::SUCCESS;
        }

        $report = $fanout->sync($clear ? null : $hooks, $only);

        $hadUserEdit = false;
        foreach ($report as $engine => $row) {
            $status = $row['status'];
            $path = $row['path'] ?? '';
            $line = match ($status) {
                'written'     => "<info>+</info> [{$engine}] written → {$path}",
                'cleared'     => "<info>-</info> [{$engine}] cleared from {$path}",
                'unchanged'   => "<comment>·</comment> [{$engine}] unchanged at {$path}",
                'user_edited' => "<comment>!</comment> [{$engine}] user-edited at {$path} — refusing to overwrite (re-run with --clear first to reset)",
                'unavailable' => "<comment>·</comment> [{$engine}] config dir not reachable — skipped",
                'unknown'     => "<comment>?</comment> [{$engine}] no writer registered for this engine",
                default       => "[{$engine}] status: {$status}",
            };
            $output->writeln($line);
            if ($status === 'user_edited') {
                $hadUserEdit = true;
            }
        }

        return $hadUserEdit ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Build a fanout populated with the writers we ship by default.
     * Hosts that want to add Kiro / Kimi / a custom engine override the
     * `HookFanoutService` singleton in their own ServiceProvider before
     * the command runs.
     */
    private function buildFanout(InputInterface $input, string $cwd): HookFanoutService
    {
        $fanout = new HookFanoutService();

        $claudeSettings = (string) ($input->getOption('claude-settings')
            ?? rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'settings.json');
        $claudeManifest = dirname($claudeSettings) . DIRECTORY_SEPARATOR . '.superaicore-manifest.json';
        $fanout->register(new ClaudeHookWriter($claudeSettings, new Manifest($claudeManifest)));

        $copilotHome = (string) ($input->getOption('copilot-home') ?? CopilotSyncCommand::defaultHome());
        $copilotConfig = rtrim($copilotHome, '/\\') . DIRECTORY_SEPARATOR . 'config.json';
        $copilotManifest = rtrim($copilotHome, '/\\') . DIRECTORY_SEPARATOR . '.superaicore-manifest.json';
        $fanout->register(new CopilotHookWriter($copilotConfig, new Manifest($copilotManifest)));

        return $fanout;
    }
}
