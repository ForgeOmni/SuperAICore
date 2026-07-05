<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\RunStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore resume --session-id <id> "<follow-up>"` — continue a prior
 * CLI conversation (ai-dispatch parity). The run store tells us which
 * backend/model produced the session, so the caller writes only the delta
 * question — never a re-pasted history. Resume never falls back to a
 * different engine: the conversation lives in ONE engine's session store.
 *
 * Note the returned `session_id` may differ from the one passed in —
 * claude forks a fresh id on `--resume`. Always chain follow-ups off the
 * LATEST result's session_id.
 */
#[AsCommand(name: 'resume', description: 'Continue a previous send session on its owning backend')]
class ResumeCommand extends SendCommand
{
    public function __construct(
        protected ?RunStore $runs = null,
        ?\SuperAICore\Services\BackendRegistry $backends = null,
        ?\SuperAICore\Services\DispatchSender $sender = null,
    ) {
        parent::__construct($backends, $sender);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::OPTIONAL, 'The follow-up prompt — only the new question, never copied history')
            ->addOption('session-id', null, InputOption::VALUE_REQUIRED, 'Session id from a previous send/resume result')
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'Owning backend override when the session is not in the run store')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model override (defaults to the original run\'s model)')
            ->addOption('prompt-file', null, InputOption::VALUE_REQUIRED, 'Read the follow-up prompt from a file')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory for CLI engines')
            ->addOption('task-name', null, InputOption::VALUE_REQUIRED, 'Label for run store, process monitor, and usage rows')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Hard timeout in seconds')
            ->addOption('json-result', null, InputOption::VALUE_NONE, 'Emit the full JSON result contract')
            ->addOption('stream-progress', null, InputOption::VALUE_NONE, 'Tee live engine output to stderr while running');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = (string) ($input->getOption('session-id') ?? '');
        if ($sessionId === '') {
            $output->writeln('<error>--session-id is required.</error>');
            return Command::FAILURE;
        }

        $prompt = (string) ($input->getArgument('prompt') ?? '');
        if ($file = $input->getOption('prompt-file')) {
            if (!is_file($file)) {
                $output->writeln("<error>Prompt file not found: {$file}</error>");
                return Command::FAILURE;
            }
            $prompt = (string) file_get_contents($file);
        }
        if (trim($prompt) === '') {
            $output->writeln('<error>Provide a follow-up prompt argument or --prompt-file.</error>');
            return Command::FAILURE;
        }

        $previous = $this->runStore()->findBySession($sessionId);
        $backend = $input->getOption('backend') ?? $previous['backend_used'] ?? null;
        if ($backend === null) {
            $output->writeln("<error>Session {$sessionId} not found in the run store — pass --backend to name the owning engine.</error>");
            return Command::FAILURE;
        }
        $model = $input->getOption('model') ?? $previous['model_used'] ?? null;

        $options = array_filter([
            'cwd' => $input->getOption('cwd'),
            'task_name' => $input->getOption('task-name'),
            'timeout' => $input->getOption('timeout') !== null ? (int) $input->getOption('timeout') : null,
        ], fn ($v) => $v !== null);
        $options['resume_session_id'] = $sessionId;
        $options['check_availability'] = false;
        if ($input->getOption('stream-progress')) {
            $err = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $options['onChunk'] = function (string $chunk) use ($err): void {
                $err->write($chunk);
            };
        }

        $result = $this->dispatchSender()->send(
            $sessionId,
            'resume',
            [['backend' => (string) $backend, 'model' => is_string($model) && $model !== '' ? $model : null]],
            $prompt,
            $options,
        );

        return $this->render($result, $input, $output);
    }

    protected function runStore(): RunStore
    {
        return $this->runs ??= new RunStore();
    }
}
