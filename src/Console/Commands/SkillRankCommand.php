<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Services\SkillRanker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skill:rank',
    description: 'Rank skills by relevance to a query (BM25 + telemetry boost).'
)]
final class SkillRankCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::IS_ARRAY, 'The task / query text')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows', '8')
            ->addOption('no-telemetry', null, InputOption::VALUE_NONE, 'Disable telemetry boost')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED,
                'Project root containing .claude/skills (auto-detected by walking up from cwd)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table | json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $words = $input->getArgument('query');
        if (!$words) {
            $output->writeln('<error>Usage: skill:rank "your task description here"</error>');
            return Command::FAILURE;
        }
        $query = is_array($words) ? implode(' ', $words) : (string) $words;

        $cwd = $input->getOption('cwd') ?: self::detectProjectRoot();
        $ranker = new SkillRanker(
            new SkillRegistry($cwd),
            useTelemetry: !$input->getOption('no-telemetry'),
        );
        $limit = max(1, (int) $input->getOption('limit'));
        $results = $ranker->rank($query, $limit);

        if ($input->getOption('format') === 'json') {
            $payload = array_map(fn($r) => [
                'skill' => $r['skill']->name,
                'score' => $r['score'],
                'description' => $r['skill']->description,
                'source' => $r['skill']->source,
                'path' => $r['skill']->path,
                'breakdown' => $r['breakdown'],
            ], $results);
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        if (!$results) {
            $output->writeln('<comment>No skills matched.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Skill', 'Score', 'Boost', 'Description']);
        foreach ($results as $r) {
            $boost = $r['breakdown']['tel_boost'] ?? 1.0;
            $boostTag = $boost > 1.0 ? '<info>' . sprintf('%.2fx', $boost) . '</info>'
                     : ($boost < 1.0 ? '<comment>' . sprintf('%.2fx', $boost) . '</comment>'
                     : '1.00x');
            $table->addRow([
                $r['skill']->name,
                sprintf('%.3f', $r['score']),
                $boostTag,
                $this->truncate((string) ($r['skill']->description ?? ''), 70),
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /** Walk up from cwd looking for a .claude/skills directory. */
    public static function detectProjectRoot(): ?string
    {
        $cwd = getcwd();
        if (!$cwd) return null;
        for ($i = 0; $i < 6; $i++) {
            if (is_dir($cwd . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'skills')) {
                return $cwd;
            }
            $parent = dirname($cwd);
            if ($parent === $cwd) break;
            $cwd = $parent;
        }
        return null;
    }
}
