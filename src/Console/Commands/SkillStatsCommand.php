<?php

namespace SuperAICore\Console\Commands;

use Carbon\Carbon;
use SuperAICore\Services\SkillTelemetry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skill:stats',
    description: 'Show skill telemetry — applied / completed / failed / failure-rate / last-used.'
)]
final class SkillStatsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('skill', null, InputOption::VALUE_REQUIRED, 'Filter to a single skill name')
            ->addOption('since', null, InputOption::VALUE_REQUIRED,
                'Time window — relative ("7d", "24h") or ISO date', null)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows', '50')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED,
                'applied | failure_rate | last_used', 'applied')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table | json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = $this->parseSince($input->getOption('since'));
        $skill = $input->getOption('skill');
        $metrics = SkillTelemetry::metrics($since, $skill ? (string) $skill : null);

        if (!$metrics) {
            $msg = 'No telemetry yet — invoke a skill (PreToolUse hook will record it).';
            if ($input->getOption('format') === 'json') {
                $output->writeln(json_encode([], JSON_PRETTY_PRINT));
            } else {
                $output->writeln("<comment>{$msg}</comment>");
            }
            return Command::SUCCESS;
        }

        $sort = $input->getOption('sort');
        uasort($metrics, function ($a, $b) use ($sort) {
            return match ($sort) {
                'failure_rate' => $b['failure_rate'] <=> $a['failure_rate'],
                'last_used'    => strcmp((string) $b['last_used_at'], (string) $a['last_used_at']),
                default        => $b['applied'] <=> $a['applied'],
            };
        });

        $limit = max(1, (int) $input->getOption('limit'));
        $metrics = array_slice($metrics, 0, $limit, true);

        if ($input->getOption('format') === 'json') {
            $payload = [];
            foreach ($metrics as $name => $m) {
                $payload[] = ['skill' => $name] + $m;
            }
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Skill', 'Applied', 'Done', 'Failed', 'Other', 'Fail %', 'Last Used']);
        foreach ($metrics as $name => $m) {
            $other = ($m['orphaned'] ?? 0) + ($m['interrupted'] ?? 0) + ($m['in_progress'] ?? 0);
            $failPct = $m['failure_rate'] > 0 ? sprintf('%.1f%%', $m['failure_rate'] * 100) : '0%';
            $tag = $m['failure_rate'] >= 0.30 ? '<error>' . $failPct . '</error>'
                 : ($m['failure_rate'] >= 0.10 ? '<comment>' . $failPct . '</comment>' : $failPct);
            $table->addRow([
                $name,
                $m['applied'],
                $m['completed'],
                $m['failed'],
                $other,
                $tag,
                $m['last_used_at'] ?? '-',
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }

    private function parseSince(?string $value): ?Carbon
    {
        if (!$value) return null;
        $value = trim($value);
        if (preg_match('/^(\d+)\s*([smhdw])$/i', $value, $m)) {
            $n = (int) $m[1];
            return match (strtolower($m[2])) {
                's' => Carbon::now()->subSeconds($n),
                'm' => Carbon::now()->subMinutes($n),
                'h' => Carbon::now()->subHours($n),
                'd' => Carbon::now()->subDays($n),
                'w' => Carbon::now()->subWeeks($n),
            };
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
