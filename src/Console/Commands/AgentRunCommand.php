<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\Agent;
use SuperAICore\Registry\AgentRegistry;
use SuperAICore\Runner\AgentRunner;
use SuperAICore\Runner\ClaudeAgentRunner;
use SuperAICore\Runner\CodexAgentRunner;
use SuperAICore\Runner\CopilotAgentRunner;
use SuperAICore\Runner\GeminiAgentRunner;
use SuperAICore\Services\ClaudeModelResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'agent:run',
    description: 'Run a Claude sub-agent with a task prompt against a backend CLI'
)]
final class AgentRunCommand extends Command
{
    /** @param array<string,AgentRunner>|null $runners keyed by backend key */
    public function __construct(
        private readonly ?AgentRegistry $registry = null,
        private readonly ?array $runners = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Agent name')
            ->addArgument('task', InputArgument::REQUIRED, 'Task prompt for the agent')
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'Override backend: claude|codex|gemini|copilot. When omitted, inferred from the agent\'s `model:` frontmatter.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the resolved command without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name   = (string) $input->getArgument('name');
        $task   = (string) $input->getArgument('task');
        $dryRun = (bool)   $input->getOption('dry-run');

        $registry = $this->registry ?? new AgentRegistry();
        $agent = $registry->get($name);
        if (!$agent) {
            $output->writeln('<error>Agent not found: ' . $name . '</error>');
            return Command::FAILURE;
        }

        $backend = $input->getOption('backend') ?: $this->inferBackend($agent->model);

        $runner = $this->runners[$backend] ?? $this->defaultRunnerFor($backend, $output);
        if (!$runner) {
            $output->writeln('<error>No agent runner available for backend: ' . $backend . '</error>');
            return Command::FAILURE;
        }

        return $runner->runAgent($agent, $task, $dryRun);
    }

    private function defaultRunnerFor(string $backend, OutputInterface $output): ?AgentRunner
    {
        $writer = fn(string $chunk) => $output->write($chunk);
        return match ($backend) {
            'claude'  => new ClaudeAgentRunner(writer: $writer),
            'codex'   => new CodexAgentRunner(writer: $writer),
            'gemini'  => new GeminiAgentRunner(writer: $writer),
            'copilot' => new CopilotAgentRunner(writer: $writer),
            default   => null,
        };
    }

    private function inferBackend(?string $model): string
    {
        if (!$model) {
            return 'claude';
        }
        if (isset(ClaudeModelResolver::FAMILIES[$model]) || str_starts_with($model, 'claude-')) {
            return 'claude';
        }
        if (str_starts_with($model, 'gemini-')) {
            return 'gemini';
        }
        if (str_starts_with($model, 'gpt-') || preg_match('/^o[1-9](-|$)/', $model)) {
            return 'codex';
        }
        return 'claude';
    }
}
