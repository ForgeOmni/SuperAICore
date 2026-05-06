<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Runner\TaskRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'super-ai-core:fallback-policy',
    description: 'Inspect TaskRunner fallback profiles, chain resolution, limits, and cooldown state'
)]
final class FallbackPolicyCommand extends Command
{
    public function __construct(private ?TaskRunner $runner = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backend', InputArgument::OPTIONAL, 'Primary backend to explain', 'claude_cli')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Fallback profile to explain')
            ->addOption('task-type', null, InputOption::VALUE_REQUIRED, 'Task type to explain')
            ->addOption('capability', null, InputOption::VALUE_REQUIRED, 'Capability to explain')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backend = (string) $input->getArgument('backend');
        $options = array_filter([
            'fallback_profile' => $input->getOption('profile') ?: null,
            'task_type' => $input->getOption('task-type') ?: null,
            'capability' => $input->getOption('capability') ?: null,
        ], fn($value) => $value !== null);

        $config = $this->configArray('super-ai-core.task_fallback');
        $explain = $this->runner?->explainFallbackChain($backend, $options) ?? [
            'primary_backend' => $backend,
            'chain' => $this->chainFromConfig($config, $options),
            'runnable_chain' => [],
            'skipped' => [],
            'source' => 'config',
        ];

        $payload = [
            'explain' => $explain,
            'profiles' => $config['chains_by_profile'] ?? [],
            'task_types' => $config['chains_by_task_type'] ?? [],
            'capabilities' => $config['chains_by_capability'] ?? [],
            'metadata' => $config['chains_by_metadata'] ?? [],
            'limits' => [
                'max_attempts' => $config['max_attempts'] ?? 0,
                'max_cost_usd' => $config['max_cost_usd'] ?? 0,
                'backoff_ms' => $config['backoff_ms'] ?? 0,
                'backoff_strategy' => $config['backoff_strategy'] ?? 'fixed',
                'cooldown' => $config['cooldown'] ?? [],
            ],
            'failure_classes' => $config['failure_classes'] ?? [],
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $output->writeln('<info>TaskRunner fallback policy</info>');
        $output->writeln('Source: ' . ($explain['source'] ?? 'unknown'));
        $output->writeln('Chain: ' . implode(' -> ', (array) ($explain['chain'] ?? [])));
        if (!empty($explain['runnable_chain'])) {
            $output->writeln('Runnable: ' . implode(' -> ', (array) $explain['runnable_chain']));
        }
        if (!empty($explain['skipped'])) {
            $table = new Table($output);
            $table->setHeaders(['Skipped backend', 'Reason', 'Cooldown']);
            foreach ($explain['skipped'] as $row) {
                $table->addRow([
                    $row['backend'] ?? '-',
                    $row['reason'] ?? '-',
                    $row['cooldown_remaining_seconds'] ?? '-',
                ]);
            }
            $table->render();
        }

        $table = new Table($output);
        $table->setHeaders(['Profile', 'Chain']);
        foreach ((array) ($config['chains_by_profile'] ?? []) as $name => $chain) {
            $table->addRow([(string) $name, implode(' -> ', (array) $chain)]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function configArray(string $key): array
    {
        if (!function_exists('config')) {
            return [];
        }

        try {
            $value = config($key);
            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function chainFromConfig(array $config, array $options): array
    {
        $profile = $options['fallback_profile'] ?? null;
        if (is_string($profile) && isset($config['chains_by_profile'][$profile]) && is_array($config['chains_by_profile'][$profile])) {
            return $config['chains_by_profile'][$profile];
        }

        return is_array($config['chain'] ?? null) ? $config['chain'] : [];
    }
}
