<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\ApiHealthDetector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Probe every configured API provider (anthropic / openai / gemini / kimi /
 * qwen / glm / minimax / openrouter) with a 5s cURL hit against its
 * cheapest listing endpoint. Surfaces an auth-rejected vs network-timeout
 * vs no-key distinction so operators can spot dead credentials from one row.
 *
 * Default behaviour lists only providers whose API-key env var is set —
 * pass `--all` to also probe the unset ones (they'll report `no API key`).
 */
#[AsCommand(
    name: 'api:status',
    description: 'Probe each configured API provider for reachability + auth (5s timeout per probe)'
)]
final class ApiStatusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('providers', null, InputOption::VALUE_REQUIRED,
                'Comma-separated subset (default: every provider with an API-key env var set)')
            ->addOption('all', null, InputOption::VALUE_NONE,
                'Probe every provider in DEFAULT_PROVIDERS, even without an API key')
            ->addOption('json', null, InputOption::VALUE_NONE,
                'Emit raw detector output as JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providers = $this->resolveProviders($input);
        $rows = ApiHealthDetector::checkMany($providers);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln('<comment>No providers probed — no API-key env vars are set. Use --all to probe every provider anyway.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Provider', 'Status', 'Latency', 'Detail']);
        foreach ($rows as $row) {
            $status  = $row['ok'] ? '<info>ok</info>' : '<error>down</error>';
            $latency = $row['latency_ms'] !== null ? $row['latency_ms'] . ' ms' : '-';
            $detail  = $row['reason'] ?? '-';
            $table->addRow([$row['provider'], $status, $latency, $detail]);
        }
        $table->render();

        $bad = array_filter($rows, fn ($r) => !$r['ok']);
        return $bad === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>|null  null = use detector default (env-filtered)
     */
    private function resolveProviders(InputInterface $input): ?array
    {
        $raw = (string) ($input->getOption('providers') ?? '');
        if ($raw !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        if ($input->getOption('all')) {
            return ApiHealthDetector::DEFAULT_PROVIDERS;
        }
        return null;
    }
}
