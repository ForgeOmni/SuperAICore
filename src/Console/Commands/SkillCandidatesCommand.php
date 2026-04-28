<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Models\SkillEvolutionCandidate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skill:candidates',
    description: 'List or inspect skill evolution candidates.'
)]
final class SkillCandidatesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('skill', null, InputOption::VALUE_REQUIRED, 'Filter by skill name')
            ->addOption('status', null, InputOption::VALUE_REQUIRED,
                'pending | reviewing | applied | rejected | superseded')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Show full detail for a single candidate id')
            ->addOption('show-prompt', null, InputOption::VALUE_NONE, 'Print the LLM prompt')
            ->addOption('show-diff', null, InputOption::VALUE_NONE, 'Print the proposed diff (if any)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows', '20')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table | json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('id');
        if ($id) {
            return $this->showOne((int) $id, $input, $output);
        }

        $q = SkillEvolutionCandidate::query();
        if ($skill = $input->getOption('skill')) {
            $q->where('skill_name', strtolower((string) $skill));
        }
        if ($status = $input->getOption('status')) {
            $q->where('status', (string) $status);
        }
        $q->orderByDesc('id');
        $rows = $q->limit(max(1, (int) $input->getOption('limit')))->get();

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode($rows->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $output->writeln('<comment>No candidates.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Skill', 'Trigger', 'Status', 'Has Diff', 'Created', 'Rationale']);
        foreach ($rows as $r) {
            $table->addRow([
                $r->id,
                $r->skill_name,
                $r->trigger_type,
                $r->status,
                $r->proposed_diff ? 'yes' : '-',
                (string) $r->created_at,
                $this->truncate((string) ($r->rationale ?? ''), 60),
            ]);
        }
        $table->render();
        $output->writeln('');
        $output->writeln('<comment>Inspect:</comment> php artisan skill:candidates --id=<ID> --show-prompt --show-diff');
        return Command::SUCCESS;
    }

    private function showOne(int $id, InputInterface $input, OutputInterface $output): int
    {
        $r = SkillEvolutionCandidate::find($id);
        if (!$r) {
            $output->writeln("<error>Candidate #{$id} not found.</error>");
            return Command::FAILURE;
        }

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode($r->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $output->writeln("<info>Candidate #{$r->id}</info>");
        $output->writeln("  skill:     {$r->skill_name}");
        $output->writeln("  trigger:   {$r->trigger_type}");
        $output->writeln("  status:    {$r->status}");
        $output->writeln("  created:   {$r->created_at}");
        $output->writeln("  rationale: " . ($r->rationale ?? '-'));
        if ($r->execution_id) $output->writeln("  exec_id:   {$r->execution_id}");

        if ($input->getOption('show-prompt') && $r->llm_prompt) {
            $output->writeln('');
            $output->writeln('<comment>=== LLM Prompt ===</comment>');
            $output->writeln($r->llm_prompt);
        }
        if ($input->getOption('show-diff')) {
            $output->writeln('');
            if ($r->proposed_diff) {
                $output->writeln('<comment>=== Proposed Diff ===</comment>');
                $output->writeln($r->proposed_diff);
            } else {
                $output->writeln('<comment>No diff stored. Run with --dispatch on skill:evolve to generate one.</comment>');
            }
        }
        return Command::SUCCESS;
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
