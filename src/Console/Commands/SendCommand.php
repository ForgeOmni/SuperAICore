<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\AliasRouter;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\DispatchSender;
use SuperAICore\Services\RunStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore send <target> "<task>"` — ai-dispatch-style one-shot
 * dispatch. The target is a short name (`opus`, `kimi`, `codex`, …), a
 * backend name (`claude_cli`), or a raw model id; the AliasRouter turns
 * it into a candidate pool that DispatchSender walks with transparent
 * degradation (route_trace + degraded flag, quota/auth/network fall
 * through, runtime failures fail closed).
 *
 * Machine callers pass `--json-result` and must inspect `ok`, `status`,
 * `backend_used`, `model_used`, `route_trace`, `degraded`, `session_id`,
 * and `failure_class` rather than assuming the requested target answered.
 */
#[AsCommand(name: 'send', description: 'Dispatch a prompt to a short-name target with candidate fallback')]
class SendCommand extends Command
{
    public function __construct(
        protected ?BackendRegistry $backends = null,
        protected ?DispatchSender $sender = null,
        protected ?AliasRouter $router = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::REQUIRED, 'Alias (opus, kimi, …), backend name, or model id')
            ->addArgument('prompt', InputArgument::OPTIONAL, 'The task prompt (omit when using --prompt-file)')
            ->addOption('prompt-file', null, InputOption::VALUE_REQUIRED, 'Read the prompt from a file (use for long prompts)')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory for CLI engines (pass "$PWD" for project work)')
            ->addOption('task-name', null, InputOption::VALUE_REQUIRED, 'Label for run store, process monitor, and usage rows')
            ->addOption('session-id', null, InputOption::VALUE_REQUIRED, 'Session id to stamp on the run (engines that support it)')
            ->addOption('system', 's', InputOption::VALUE_REQUIRED, 'System prompt')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Hard timeout in seconds')
            ->addOption('max-tokens', null, InputOption::VALUE_REQUIRED, 'Max output tokens (API backends)')
            ->addOption('json-result', null, InputOption::VALUE_NONE, 'Emit the full JSON result contract')
            ->addOption('stream-progress', null, InputOption::VALUE_NONE, 'Tee live engine output to stderr while running')
            ->addOption('no-check', null, InputOption::VALUE_NONE, 'Skip the backend availability pre-check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = (string) ($input->getArgument('prompt') ?? '');
        if ($file = $input->getOption('prompt-file')) {
            if (!is_file($file)) {
                $output->writeln("<error>Prompt file not found: {$file}</error>");
                return Command::FAILURE;
            }
            $prompt = (string) file_get_contents($file);
        }
        if (trim($prompt) === '') {
            $output->writeln('<error>Provide a prompt argument or --prompt-file.</error>');
            return Command::FAILURE;
        }

        $route = $this->aliasRouter()->resolve((string) $input->getArgument('target'));

        $options = array_filter([
            'cwd' => $input->getOption('cwd'),
            'system' => $input->getOption('system'),
            'task_name' => $input->getOption('task-name'),
            'session_id' => $input->getOption('session-id'),
            'timeout' => $input->getOption('timeout') !== null ? (int) $input->getOption('timeout') : null,
            'max_tokens' => $input->getOption('max-tokens') !== null ? (int) $input->getOption('max-tokens') : null,
        ], fn ($v) => $v !== null);
        $options['check_availability'] = !$input->getOption('no-check');
        if ($input->getOption('stream-progress')) {
            $err = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $options['onChunk'] = function (string $chunk) use ($err): void {
                $err->write($chunk);
            };
        }

        $result = $this->dispatchSender()->send(
            $route['requested'],
            $route['source'],
            $route['candidates'],
            $prompt,
            $options,
        );

        return $this->render($result, $input, $output);
    }

    /** Shared renderer — ResumeCommand reuses the same contract output. */
    protected function render(array $result, InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('json-result')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($result['ok']) {
            $output->writeln($result['text']);
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>target=%s backend=%s model=%s session=%s degraded=%s cost=$%.6f time=%dms run=%s</comment>',
                $result['requested_target'],
                $result['backend_used'] ?? '?',
                $result['model_used'] ?? '?',
                $result['session_id'] ?? '-',
                $result['degraded'] ? 'yes (' . ($result['degrade_reason'] ?? '?') . ')' : 'no',
                $result['cost_usd'] ?? 0,
                $result['duration_ms'] ?? 0,
                $result['run_id'] ?? '-',
            ));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>Dispatch failed (status=%s, failure_class=%s)</error>',
            $result['status'],
            $result['failure_class'] ?? 'unknown',
        ));
        foreach ($result['route_trace'] as $step) {
            $output->writeln(sprintf(
                '<comment>  %s%s → %s%s</comment>',
                $step['backend'],
                isset($step['model']) && $step['model'] !== null ? ':' . $step['model'] : '',
                $step['status'],
                isset($step['reason']) ? " ({$step['reason']})" : '',
            ));
        }
        return Command::FAILURE;
    }

    protected function backendRegistry(): BackendRegistry
    {
        return $this->backends ??= new BackendRegistry();
    }

    protected function aliasRouter(): AliasRouter
    {
        return $this->router ??= new AliasRouter($this->backendRegistry());
    }

    protected function dispatchSender(): DispatchSender
    {
        if ($this->sender === null) {
            $backends = $this->backendRegistry();
            $this->sender = new DispatchSender(
                new Dispatcher($backends, new CostCalculator()),
                $backends,
                new RunStore(),
            );
        }
        return $this->sender;
    }
}
