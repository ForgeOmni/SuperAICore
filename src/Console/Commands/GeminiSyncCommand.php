<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\AgentRegistry;
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Sync\GeminiCommandWriter;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'gemini:sync',
    description: 'Generate/refresh Gemini custom-command TOMLs (/skill:*, /agent:*) for every discovered skill and agent'
)]
final class GeminiSyncCommand extends Command
{
    public function __construct(
        private readonly ?SkillRegistry $skills = null,
        private readonly ?AgentRegistry $agents = null,
        private readonly ?GeminiCommandWriter $writer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the +/- change table without writing files')
            ->addOption('gemini-home', null, InputOption::VALUE_REQUIRED, 'Override Gemini config home (default: $HOME/.gemini)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $home = (string) ($input->getOption('gemini-home')
            ?? ($_SERVER['HOME'] ?? getenv('HOME') ?: '') . '/.gemini');
        $commandsDir = rtrim($home, '/') . '/commands';
        $manifestPath = $commandsDir . '/.superaicore-manifest.json';

        $skills = ($this->skills ?? new SkillRegistry())->all();
        $agents = ($this->agents ?? new AgentRegistry())->all();

        $writer = $this->writer ?? new GeminiCommandWriter($commandsDir, new Manifest($manifestPath));
        $report = $writer->sync(array_values($skills), array_values($agents), (bool) $input->getOption('dry-run'));

        $output->writeln(sprintf(
            'Sync %s → %s',
            (bool) $input->getOption('dry-run') ? 'plan' : 'complete',
            $commandsDir
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
        if (!$paths) {
            return;
        }
        $output->writeln("<comment>[{$label}] " . count($paths) . '</comment>');
        foreach ($paths as $p) {
            $output->writeln("  {$marker} {$p}");
        }
    }
}
