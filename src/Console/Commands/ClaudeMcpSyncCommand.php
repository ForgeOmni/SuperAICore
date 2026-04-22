<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\McpCatalog;
use SuperAICore\Services\McpManager;
use SuperAICore\Sync\ClaudeAgentMcpWriter;
use SuperAICore\Sync\ClaudeProjectMcpWriter;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate project-scope `.mcp.json` and per-agent `mcpServers:` frontmatter
 * blocks from a catalog JSON + a host-local mapping file.
 *
 * Host mapping (default `.claude/mcp-host.json`):
 *   {
 *     "catalog": ".mcp-servers/mcp-catalog.json",
 *     "project": {
 *       "enabled": true,
 *       "path": ".mcp.json",
 *       "servers": ["fetch", "sqlite", "timezone", "serp", "playwright", "osm-mcp"]
 *     },
 *     "agents": {
 *       "enabled": true,
 *       "dir": ".claude/agents",
 *       "manifest": ".claude/agents/.superaicore-mcp-manifest.json",
 *       "assignments": {
 *         "research-jordan": ["arxiv", "pubmed", ...]
 *       }
 *     }
 *   }
 */
#[AsCommand(
    name: 'claude:mcp-sync',
    description: 'Sync project .mcp.json and agent-frontmatter mcpServers blocks from a catalog + host mapping'
)]
final class ClaudeMcpSyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host-config', null, InputOption::VALUE_REQUIRED,
                'Host mapping file (default: .claude/mcp-host.json, resolved from CWD)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Print the +/- change table without writing files')
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED,
                'Override project root (default: CWD) — all relative paths in the host config resolve against this')
            ->addOption('no-propagate', null, InputOption::VALUE_NONE,
                'Skip propagating the new .mcp.json to per-backend user-scope configs (Claude / Codex / Gemini / Copilot / Kiro)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = rtrim((string) ($input->getOption('project-root') ?: getcwd() ?: '.'), '/');
        $hostConfigOpt = (string) ($input->getOption('host-config') ?: '.claude/mcp-host.json');
        $hostConfigPath = self::resolve($projectRoot, $hostConfigOpt);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($hostConfigPath)) {
            $output->writeln("<error>Host config not found: {$hostConfigPath}</error>");
            $output->writeln("Create one at `.claude/mcp-host.json` — see `ClaudeMcpSyncCommand` docblock for shape.");
            return Command::FAILURE;
        }

        $hostConfig = json_decode((string) file_get_contents($hostConfigPath), true);
        if (!is_array($hostConfig)) {
            $output->writeln("<error>Host config not valid JSON: {$hostConfigPath}</error>");
            return Command::FAILURE;
        }

        $catalogPath = self::resolve($projectRoot,
            (string) ($hostConfig['catalog'] ?? '.mcp-servers/mcp-catalog.json'));

        try {
            $catalog = new McpCatalog($catalogPath);
        } catch (\Throwable $e) {
            $output->writeln("<error>Catalog load failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Catalog:</info> %s (%d servers)  |  <info>Host:</info> %s  |  <info>Mode:</info> %s',
            $catalogPath, count($catalog->names()), $hostConfigPath, $dryRun ? 'dry-run' : 'write'
        ));
        $output->writeln('');

        $any = false;

        // --- Project `.mcp.json` ---
        $projCfg = (array) ($hostConfig['project'] ?? []);
        if (!empty($projCfg['enabled'])) {
            $any = true;
            $mcpJsonPath = self::resolve($projectRoot, (string) ($projCfg['path'] ?? '.mcp.json'));
            $names = array_values((array) ($projCfg['servers'] ?? []));
            try {
                $subset = $catalog->subset($names);
            } catch (\Throwable $e) {
                $output->writeln("<error>project.servers: {$e->getMessage()}</error>");
                return Command::FAILURE;
            }
            $manifestPath = self::resolve($projectRoot,
                (string) ($projCfg['manifest'] ?? '.claude/.superaicore-mcp-project-manifest.json'));
            $writer = new ClaudeProjectMcpWriter($mcpJsonPath, new Manifest($manifestPath));
            $r = $writer->sync($subset, $dryRun);
            $output->writeln(sprintf(
                '<comment>project .mcp.json</comment>: %s  →  %s  (%d servers)',
                $r['status'], $r['path'], count($subset)
            ));
        } else {
            $output->writeln('<comment>project</comment>: skipped (not enabled in host config)');
        }

        // --- Agent frontmatter blocks ---
        $agCfg = (array) ($hostConfig['agents'] ?? []);
        if (!empty($agCfg['enabled'])) {
            $any = true;
            $agentsDir = self::resolve($projectRoot, (string) ($agCfg['dir'] ?? '.claude/agents'));
            $manifestPath = self::resolve($projectRoot,
                (string) ($agCfg['manifest'] ?? '.claude/agents/.superaicore-mcp-manifest.json'));
            $assignments = (array) ($agCfg['assignments'] ?? []);
            $writer = new ClaudeAgentMcpWriter($agentsDir, new Manifest($manifestPath));
            try {
                $r = $writer->sync($assignments, $catalog->subset(self::collectNames($assignments)), $dryRun);
            } catch (\Throwable $e) {
                $output->writeln("<error>agents: {$e->getMessage()}</error>");
                return Command::FAILURE;
            }
            $output->writeln('');
            $output->writeln('<comment>agents</comment>:');
            $this->printSection($output, '+', 'written',     $r['written']);
            $this->printSection($output, '·', 'unchanged',   $r['unchanged']);
            $this->printSection($output, '!', 'user-edited', $r['user_edited']);
            $this->printSection($output, '?', 'missing',     $r['missing']);
        } else {
            $output->writeln('<comment>agents</comment>: skipped (not enabled in host config)');
        }

        if (!$any) {
            $output->writeln('<error>Nothing to do — both project and agents are disabled in the host config</error>');
            return Command::FAILURE;
        }

        // --- Propagate to per-backend user-scope configs ---
        // After the project `.mcp.json` is fresh, push the same server set to
        // each installed CLI backend's own config (~/.claude.json, ~/.codex/,
        // ~/.gemini/..., ~/.copilot/..., ~/.kiro/...). Without this step a
        // backend keeps stale servers from before the last host-config edit —
        // which was the RUN 63 symptom: Gemini tried to spawn 50+ servers that
        // no longer existed after the host trimmed .mcp.json.
        if (!$dryRun && !empty($projCfg['enabled']) && !$input->getOption('no-propagate')) {
            $output->writeln('');
            $output->writeln('<comment>propagate</comment>: pushing new .mcp.json to per-backend user-scope configs');
            try {
                $report = McpManager::syncAllBackends();
                foreach ($report as $row) {
                    $backend = (string) ($row['backend'] ?? '?');
                    $path    = (string) ($row['path'] ?? '');
                    $bytes   = (int)    ($row['bytes'] ?? 0);
                    $err     = $row['error'] ?? null;
                    if ($err) {
                        $output->writeln("  <fg=red>✗</> {$backend} — {$err}");
                    } elseif ($bytes > 0 && $path) {
                        $output->writeln("  <fg=green>✓</> {$backend} → {$path} ({$bytes} bytes)");
                    } else {
                        $output->writeln("  <fg=yellow>·</> {$backend} (skipped)");
                    }
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>propagate failed: {$e->getMessage()}</error>");
                // Non-fatal: project/agent writes already succeeded. Tell the
                // operator to invoke `mcp:sync-backends` manually instead.
                $output->writeln("<comment>  → run `php artisan mcp:sync-backends` to retry</comment>");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Flatten assignment map into a unique list of server names, so we only
     * pay the catalog-subset cost once for all agents combined.
     *
     * @param array<string, array<int, string>> $assignments
     * @return string[]
     */
    private static function collectNames(array $assignments): array
    {
        $seen = [];
        foreach ($assignments as $names) {
            foreach ((array) $names as $n) {
                $seen[$n] = true;
            }
        }
        return array_keys($seen);
    }

    private static function resolve(string $root, string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path))) {
            return $path;
        }
        return $root . '/' . ltrim($path, '/');
    }

    /** @param string[] $paths */
    private function printSection(OutputInterface $output, string $marker, string $label, array $paths): void
    {
        if (!$paths) return;
        $output->writeln("  <comment>[{$label}] " . count($paths) . '</comment>');
        foreach ($paths as $p) {
            $output->writeln("    {$marker} {$p}");
        }
    }
}
