<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Models\SkillEvolutionCandidate;
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\SkillEvolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate a FIX-mode evolution candidate for a skill.
 *
 *  --skill=research          Manually queue a candidate for one skill
 *  --sweep                   Sweep telemetry, queue candidates for skills
 *                             with failure_rate > threshold
 *  --dispatch                Also call the LLM (Dispatcher) and store the
 *                             proposed diff. Costs tokens.
 *
 * Never modifies SKILL.md. The output is always a row in
 * skill_evolution_candidates with status=pending — review it via
 * `php artisan skill:candidates`.
 */
#[AsCommand(
    name: 'skill:evolve',
    description: 'Queue a FIX-mode candidate for a skill (never auto-applied).'
)]
final class SkillEvolveCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('skill', null, InputOption::VALUE_REQUIRED, 'Skill name to propose a fix for')
            ->addOption('sweep', null, InputOption::VALUE_NONE,
                'Sweep all skills with degraded metrics')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED,
                'Failure-rate threshold for --sweep', '0.30')
            ->addOption('min-applied', null, InputOption::VALUE_REQUIRED,
                'Minimum applied count for --sweep', '5')
            ->addOption('dispatch', null, InputOption::VALUE_NONE,
                'Also invoke the LLM via Dispatcher (costs tokens)')
            ->addOption('execution-id', null, InputOption::VALUE_REQUIRED,
                'Anchor candidate to a specific skill_executions row id')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED,
                'Project root containing .claude/skills (auto-detected)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text | json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = $input->getOption('cwd') ?: SkillRankCommand::detectProjectRoot();
        $registry = new SkillRegistry($cwd);
        $dispatcher = $this->resolveDispatcher();
        $evolver = new SkillEvolver($registry, $dispatcher);

        if ($input->getOption('sweep')) {
            $threshold = (float) $input->getOption('threshold');
            $minApplied = max(1, (int) $input->getOption('min-applied'));
            $ids = $evolver->sweepDegraded($threshold, $minApplied);

            if ($input->getOption('format') === 'json') {
                $output->writeln(json_encode(['queued' => $ids]));
            } else {
                if ($ids) {
                    $output->writeln('<info>Queued ' . count($ids) . ' candidate(s):</info> ' . implode(', ', $ids));
                } else {
                    $output->writeln('<comment>No skills exceeded the threshold.</comment>');
                }
            }
            return Command::SUCCESS;
        }

        $skill = $input->getOption('skill');
        if (!$skill) {
            $output->writeln('<error>Pass --skill=<name> or --sweep.</error>');
            return Command::FAILURE;
        }

        $execId = $input->getOption('execution-id');
        $execId = $execId !== null && $execId !== '' ? (int) $execId : null;

        try {
            $candidate = $evolver->proposeFix(
                skillName: (string) $skill,
                triggerType: $execId
                    ? SkillEvolutionCandidate::TRIGGER_FAILURE
                    : SkillEvolutionCandidate::TRIGGER_MANUAL,
                executionId: $execId,
                dispatch: (bool) $input->getOption('dispatch'),
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode([
                'id'           => $candidate->id,
                'skill_name'   => $candidate->skill_name,
                'status'       => $candidate->status,
                'has_diff'     => (bool) $candidate->proposed_diff,
                'rationale'    => $candidate->rationale,
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln("<info>Queued candidate #{$candidate->id}</info> for skill {$candidate->skill_name}");
        $output->writeln("  trigger:    {$candidate->trigger_type}");
        $output->writeln("  rationale:  " . ($candidate->rationale ?? '-'));
        $output->writeln("  has diff:   " . ($candidate->proposed_diff ? 'yes' : 'no (run with --dispatch to invoke LLM)'));
        $output->writeln('');
        $output->writeln("Review:   php artisan skill:candidates --skill={$candidate->skill_name}");
        return Command::SUCCESS;
    }

    private function resolveDispatcher(): ?Dispatcher
    {
        if (function_exists('app')) {
            try { return app(Dispatcher::class); }
            catch (\Throwable) { return null; }
        }
        return null;
    }
}
