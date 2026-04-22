<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\AgentRegistry;
use SuperAICore\Sync\KimiAgentSync;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Translate `.claude/agents/*.md` into Kimi-CLI-compatible agent specs
 * under `~/.kimi/agents/superaicore/<name>/{agent.yaml,system.md}`.
 *
 * Motivation: verified on kimi v1.38.0 that Kimi natively scans
 * `.claude/skills/` but does NOT read `.claude/agents/*.md` — it expects
 * its own YAML shape under `~/.kimi/agents/`. This command bridges that
 * gap using `KimiAgentSync`, which owns the non-destructive manifest
 * contract (user edits are detected via sha256 and preserved).
 */
#[AsCommand(
    name: 'kimi:sync',
    description: 'Translate .claude/agents → ~/.kimi/agents/superaicore/<name>/ (idempotent, non-destructive)'
)]
final class KimiSyncCommand extends Command
{
    public function __construct(
        private readonly ?AgentRegistry $agents = null,
        private readonly ?KimiAgentSync $writer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Print the +/- change table without writing files')
            ->addOption('kimi-home', null, InputOption::VALUE_REQUIRED,
                'Override Kimi config home (default: $HOME/.kimi)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $home = (string) ($input->getOption('kimi-home') ?? self::defaultHome());
        // Namespace under `superaicore/` so we never collide with Kimi's
        // bundled `default/` / `okabe/` dirs and a re-sync never walks
        // over Moonshot-shipped agent definitions.
        $agentsDir    = rtrim($home, '/') . '/agents/superaicore';
        $manifestPath = $agentsDir . '/.superaicore-manifest.json';

        $agents = ($this->agents ?? new AgentRegistry())->all();

        $writer = $this->writer
            ?? new KimiAgentSync($agentsDir, new Manifest($manifestPath));
        $report = $writer->sync(array_values($agents), (bool) $input->getOption('dry-run'));

        $output->writeln(sprintf(
            'Kimi sync %s → %s',
            (bool) $input->getOption('dry-run') ? 'plan' : 'complete',
            $agentsDir,
        ));
        $this->printSection($output, '+', 'written',     $report['written']);
        $this->printSection($output, '·', 'unchanged',   $report['unchanged']);
        $this->printSection($output, '-', 'removed',     $report['removed']);
        $this->printSection($output, '!', 'user-edited', $report['user_edited']);
        $this->printSection($output, '!', 'stale-kept',  $report['stale_kept']);

        if ($report['written'] !== [] && !$input->getOption('dry-run')) {
            // Surface the invocation pattern once — unlike Copilot (which
            // discovers agents by filename) Kimi wants the full path.
            $example = $report['written'][0] ?? '';
            if (str_ends_with($example, '/agent.yaml')) {
                $output->writeln('');
                $output->writeln(
                    '<comment>Invoke a synced agent via:</comment>'
                );
                $output->writeln(
                    "  kimi --agent-file {$example} --print --prompt ...",
                );
            }
        }

        return Command::SUCCESS;
    }

    /** @param string[] $paths */
    private function printSection(OutputInterface $output, string $marker, string $label, array $paths): void
    {
        if (!$paths) return;
        $output->writeln("<comment>[{$label}] " . count($paths) . '</comment>');
        foreach ($paths as $p) {
            $output->writeln("  {$marker} {$p}");
        }
    }

    public static function defaultHome(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        return rtrim($home, '/') . '/.kimi';
    }
}
