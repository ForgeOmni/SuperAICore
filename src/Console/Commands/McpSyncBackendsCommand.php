<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\McpManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Push the current project-scope `.mcp.json` to every installed CLI backend's
 * own user-scope config (~/.claude.json, ~/.codex/*, ~/.gemini/*, etc.).
 *
 * `claude:mcp-sync` already calls this automatically at the end of its run
 * when the project section was enabled. This standalone command exists for:
 *   - Hand-edited `.mcp.json` (bypassing the host-config flow)
 *   - File-watcher / git-hook driven auto-sync
 *   - Recovering from a backend that drifted out of sync
 */
#[AsCommand(
    name: 'mcp:sync-backends',
    description: 'Propagate the current project .mcp.json to every installed CLI backend user-scope config'
)]
final class McpSyncBackendsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('backends', null, InputOption::VALUE_REQUIRED,
                'Comma-separated list of backends to sync (default: all that support MCP per CapabilityRegistry)')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED,
                'Override project root used to locate `.mcp.json` (default: CWD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (string) ($input->getOption('project-root') ?: '');
        if ($projectRoot !== '') {
            McpManager::setProjectRootOverride(rtrim($projectRoot, '/'));
        }

        $backends = null;
        $raw = (string) ($input->getOption('backends') ?: '');
        if ($raw !== '') {
            $backends = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        $output->writeln('<info>Reading project .mcp.json and propagating to backend user-scope configs...</info>');

        try {
            $report = McpManager::syncAllBackends($backends);
        } catch (\Throwable $e) {
            $output->writeln("<error>Sync failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $ok = 0; $err = 0; $skipped = 0;
        foreach ($report as $row) {
            $backend = (string) ($row['backend'] ?? '?');
            $path    = (string) ($row['path'] ?? '');
            $bytes   = (int)    ($row['bytes'] ?? 0);
            $e       = $row['error'] ?? null;
            if ($e) {
                $output->writeln("  <fg=red>✗</> {$backend} — {$e}");
                $err++;
            } elseif ($bytes > 0 && $path) {
                $output->writeln("  <fg=green>✓</> {$backend} → {$path} ({$bytes} bytes)");
                $ok++;
            } else {
                $output->writeln("  <fg=yellow>·</> {$backend} (skipped — backend has no MCP support or HOME unknown)");
                $skipped++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done:</info> %d written, %d skipped, %d failed',
            $ok, $skipped, $err
        ));

        return $err > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
