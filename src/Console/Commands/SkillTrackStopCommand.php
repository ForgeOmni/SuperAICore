<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\SkillTelemetry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Close all in-progress skill executions for a session. Designed to be
 * invoked from a Claude Code Stop hook with the hook payload on stdin:
 *
 *   { "session_id": "abc123", "stop_hook_active": false, ... }
 *
 * Status defaults to `completed`; pass --status=interrupted (or
 * tool_name=stop_hook_active in payload) to mark as interrupted.
 */
#[AsCommand(
    name: 'skill:track-stop',
    description: 'Close in-progress Skill executions for a session (hook target).'
)]
final class SkillTrackStopCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('session', null, InputOption::VALUE_REQUIRED, 'Session id (overrides stdin payload)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED,
                'completed | failed | interrupted', 'completed')
            ->addOption('error', null, InputOption::VALUE_REQUIRED, 'Error summary')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON on stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = $this->readStdinJson();

        $sessionId = $input->getOption('session') ?? $payload['session_id'] ?? null;
        if (!$sessionId) {
            if ($input->getOption('json')) {
                $output->writeln(json_encode(['skipped' => true, 'reason' => 'no session_id']));
            }
            return Command::SUCCESS;
        }

        $status = (string) $input->getOption('status') ?: 'completed';
        if (!in_array($status, ['completed', 'failed', 'interrupted'], true)) {
            $status = 'completed';
        }

        // Auto-flip to interrupted if Claude Code's Stop hook reports the
        // session was hard-stopped by the user.
        if (!empty($payload['stop_hook_active']) && $payload['stop_hook_active'] === false
            && !empty($payload['user_interrupted'])) {
            $status = 'interrupted';
        }

        $error = $input->getOption('error');

        try {
            $closed = SkillTelemetry::closeSession((string) $sessionId, $status, $error ? (string) $error : null);
        } catch (\Throwable $e) {
            if (!$input->getOption('quiet')) {
                $output->writeln('<comment>skill:track-stop: ' . $e->getMessage() . '</comment>');
            }
            return Command::SUCCESS;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'closed' => $closed,
                'session_id' => $sessionId,
                'status' => $status,
            ]));
        } elseif (!$input->getOption('quiet')) {
            $output->writeln("closed {$closed} skill row(s) for session {$sessionId} → {$status}");
        }
        return Command::SUCCESS;
    }

    private function readStdinJson(): array
    {
        $stdin = defined('STDIN') ? STDIN : null;
        if (!$stdin) return [];
        if (function_exists('stream_isatty') && @stream_isatty($stdin)) return [];

        $raw = '';
        $deadline = microtime(true) + 1.0;
        stream_set_blocking($stdin, false);
        while (microtime(true) < $deadline) {
            $chunk = fread($stdin, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($stdin)) break;
                usleep(20_000);
                continue;
            }
            $raw .= $chunk;
            if (mb_strlen($raw) > 200_000) break;
        }
        $raw = trim($raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
