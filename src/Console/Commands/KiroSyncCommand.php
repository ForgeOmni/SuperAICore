<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\AgentRegistry;
use SuperAICore\Sync\KiroAgentWriter;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual entry point for the Kiro agent sync. Most users never run this
 * — `agent:run --backend=kiro` auto-syncs the targeted agent. Surface
 * keeps `--dry-run` for previewing the +/- table and `--kiro-home` for
 * non-default config dirs.
 */
#[AsCommand(
    name: 'kiro:sync',
    description: 'Translate .claude/agents → ~/.kiro/agents/<name>.json (idempotent, non-destructive)'
)]
final class KiroSyncCommand extends Command
{
    public function __construct(
        private readonly ?AgentRegistry $agents = null,
        private readonly ?KiroAgentWriter $writer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the +/- change table without writing files')
            ->addOption('kiro-home', null, InputOption::VALUE_REQUIRED, 'Override Kiro config home (default: $HOME/.kiro)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $home         = (string) ($input->getOption('kiro-home') ?? self::defaultHome());
        $agentsDir    = rtrim($home, '/') . '/agents';
        $manifestPath = $agentsDir . '/.superaicore-manifest.json';

        $agents = ($this->agents ?? new AgentRegistry())->all();

        $writer = $this->writer ?? new KiroAgentWriter($agentsDir, new Manifest($manifestPath));
        $report = $writer->sync(array_values($agents), (bool) $input->getOption('dry-run'));

        $output->writeln(sprintf(
            'Kiro sync %s → %s',
            (bool) $input->getOption('dry-run') ? 'plan' : 'complete',
            $agentsDir
        ));
        $this->printSection($output, '+', 'written',     $report['written']);
        $this->printSection($output, '·', 'unchanged',   $report['unchanged']);
        $this->printSection($output, '-', 'removed',     $report['removed']);
        $this->printSection($output, '!', 'user-edited', $report['user_edited']);
        $this->printSection($output, '!', 'stale-kept',  $report['stale_kept']);

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
        return rtrim($home, '/') . '/.kiro';
    }
}
