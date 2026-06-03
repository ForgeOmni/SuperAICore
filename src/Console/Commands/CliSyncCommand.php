<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\CliSkillBridge;
use SuperAICore\Services\McpManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One command to propagate the host's whole capability surface — skills
 * AND MCP — to every installed CLI backend's native config.
 *
 *   php artisan superaicore:sync-cli                 # skills + MCP, all backends
 *   php artisan superaicore:sync-cli --skills-only
 *   php artisan superaicore:sync-cli --mcp-only
 *   php artisan superaicore:sync-cli --backends=codex,gemini
 *
 * Skills are bridged via {@see CliSkillBridge} (needs a host SkillLibrary
 * bound in the container; no-op otherwise). MCP is bridged via the
 * existing {@see McpManager::syncAllBackends()}. The same skill sync runs
 * lazily before every dispatch (TaskRunner) — this command is for manual
 * / cron / git-hook driven full refreshes.
 */
#[AsCommand(
    name: 'superaicore:sync-cli',
    description: 'Sync skills + MCP from the host capability library to every CLI backend'
)]
final class CliSyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('backends', null, InputOption::VALUE_REQUIRED,
                'Comma-separated backends to sync (default: all bridgeable)')
            ->addOption('skills-only', null, InputOption::VALUE_NONE, 'Only sync skills')
            ->addOption('mcp-only', null, InputOption::VALUE_NONE, 'Only sync MCP servers')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED,
                'Override project root used to locate `.mcp.json` (MCP step)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = (string) ($input->getOption('backends') ?: '');
        $backends = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : null;
        $skillsOnly = (bool) $input->getOption('skills-only');
        $mcpOnly    = (bool) $input->getOption('mcp-only');
        $errors = 0;

        // ─── Skills ───
        if (!$mcpOnly) {
            $output->writeln('<info>Syncing skills → CLI backends…</info>');
            $bridge = new CliSkillBridge();
            if (!$bridge->active()) {
                $output->writeln('  <fg=yellow>·</> no SkillLibrary bound — skipping skill sync');
            } else {
                foreach ($bridge->syncAll($backends) as $r) {
                    $b = $r['backend']; $mode = $r['mode'];
                    if ($r['error']) {
                        $output->writeln("  <fg=red>✗</> {$b} ({$mode}) — {$r['error']}");
                        $errors++;
                    } elseif (in_array($mode, ['source', 'none'], true)) {
                        $output->writeln("  <fg=yellow>·</> {$b} ({$mode}) — nothing to install");
                    } else {
                        $extra = $r['pruned'] > 0 ? ", {$r['pruned']} pruned" : '';
                        $output->writeln("  <fg=green>✓</> {$b} ({$mode}) — {$r['installed']} installed{$extra} → {$r['path']}");
                    }
                }
            }
            $output->writeln('');
        }

        // ─── MCP ───
        if (!$skillsOnly) {
            $pr = (string) ($input->getOption('project-root') ?: '');
            if ($pr !== '') McpManager::setProjectRootOverride(rtrim($pr, '/'));
            $output->writeln('<info>Syncing MCP servers → CLI backends…</info>');
            try {
                foreach (McpManager::syncAllBackends($backends) as $r) {
                    $b = (string) ($r['backend'] ?? '?');
                    if (!empty($r['error'])) {
                        $output->writeln("  <fg=yellow>·</> {$b} — {$r['error']}");
                    } elseif ((int) ($r['bytes'] ?? 0) > 0) {
                        $output->writeln("  <fg=green>✓</> {$b} → {$r['path']} ({$r['bytes']} bytes)");
                    } else {
                        $output->writeln("  <fg=yellow>·</> {$b} — skipped");
                    }
                }
            } catch (\Throwable $e) {
                $output->writeln("  <fg=red>✗</> MCP sync failed: {$e->getMessage()}");
                $errors++;
            }
            $output->writeln('');
        }

        $output->writeln($errors > 0
            ? "<error>Done with {$errors} error(s).</error>"
            : '<info>Done.</info>');
        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
