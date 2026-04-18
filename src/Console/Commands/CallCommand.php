<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'call', description: 'Send a prompt to any configured backend')]
class CallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::REQUIRED, 'The prompt to send')
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'Backend: anthropic_api | openai_api | gemini_api | superagent | claude_cli | codex_cli | gemini_cli | copilot_cli')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model id (e.g., claude-sonnet-4-5-20241022)')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key override')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'API base URL override')
            ->addOption('max-tokens', null, InputOption::VALUE_REQUIRED, 'Max output tokens', '500')
            ->addOption('system', 's', InputOption::VALUE_REQUIRED, 'System prompt')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output raw JSON result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backends = new BackendRegistry();
        $costs = new CostCalculator();
        $dispatcher = new Dispatcher($backends, $costs);

        $options = [
            'prompt' => $input->getArgument('prompt'),
            'max_tokens' => (int) $input->getOption('max-tokens'),
        ];

        if ($backend = $input->getOption('backend')) {
            $options['backend'] = $backend;
        }
        if ($model = $input->getOption('model')) {
            $options['model'] = $model;
        }
        if ($system = $input->getOption('system')) {
            $options['system'] = $system;
        }

        $providerConfig = [];
        if ($key = $input->getOption('api-key')) {
            $providerConfig['api_key'] = $key;
        }
        if ($url = $input->getOption('base-url')) {
            $providerConfig['base_url'] = $url;
        }
        if ($providerConfig) {
            $options['provider_config'] = $providerConfig;
        }

        $result = $dispatcher->dispatch($options);

        if (!$result) {
            $output->writeln('<error>No response — check backend availability and credentials.</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln($result['text']);
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>backend=%s model=%s tokens=%d/%d cost=$%.6f time=%dms</comment>',
                $result['backend'],
                $result['model'] ?? '?',
                $result['usage']['input_tokens'] ?? 0,
                $result['usage']['output_tokens'] ?? 0,
                $result['cost_usd'] ?? 0,
                $result['duration_ms'] ?? 0,
            ));
        }

        return Command::SUCCESS;
    }
}
