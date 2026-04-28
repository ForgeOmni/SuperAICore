<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\SkillTelemetry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Open a skill_executions row. Designed to be invoked from a Claude Code
 * PreToolUse hook with the hook payload piped on stdin:
 *
 *   {
 *     "session_id": "abc123",
 *     "transcript_path": "/path/to/transcript.jsonl",
 *     "cwd": "/abs/cwd",
 *     "tool_name": "Skill",
 *     "tool_input": { "skill": "research", "args": "..." }
 *   }
 *
 * Falls back to CLI flags when invoked manually.
 */
#[AsCommand(
    name: 'skill:track-start',
    description: 'Record the start of a Skill tool invocation (hook target).'
)]
final class SkillTrackStartCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('skill', null, InputOption::VALUE_REQUIRED, 'Skill name (overrides stdin payload)')
            ->addOption('session', null, InputOption::VALUE_REQUIRED, 'Session id (overrides stdin payload)')
            ->addOption('host-app', null, InputOption::VALUE_REQUIRED, 'Host app label (e.g. super-team)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON {id, skill, session_id} on stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = $this->readStdinJson();

        $skill = $input->getOption('skill')
            ?? $payload['tool_input']['skill']
            ?? $payload['tool_input']['name']
            ?? null;

        if (!$skill) {
            // Silent no-op — hooks fire on every Skill matcher; absence of
            // a skill name shouldn't break the user's session.
            if ($input->getOption('json')) {
                $output->writeln(json_encode(['skipped' => true, 'reason' => 'no skill in payload']));
            }
            return Command::SUCCESS;
        }

        $sessionId = $input->getOption('session') ?? $payload['session_id'] ?? null;
        $hostApp   = $input->getOption('host-app') ?? $this->detectHostApp();
        $cwd       = $payload['cwd'] ?? getcwd() ?: null;
        $transcript = $payload['transcript_path'] ?? null;

        try {
            $id = SkillTelemetry::start(
                skillName: (string) $skill,
                sessionId: $sessionId ? (string) $sessionId : null,
                hostApp: $hostApp,
                transcriptPath: $transcript ? (string) $transcript : null,
                cwd: $cwd ? (string) $cwd : null,
                metadata: $payload['tool_input'] ?? null,
            );
        } catch (\Throwable $e) {
            // Never fail the hook — telemetry is best-effort.
            if (!$input->getOption('quiet')) {
                $output->writeln('<comment>skill:track-start: ' . $e->getMessage() . '</comment>');
            }
            return Command::SUCCESS;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'id' => $id,
                'skill' => $skill,
                'session_id' => $sessionId,
            ]));
        } elseif (!$input->getOption('quiet')) {
            $output->writeln("started skill execution #{$id} ({$skill})");
        }
        return Command::SUCCESS;
    }

    private function readStdinJson(): array
    {
        // Don't block when stdin is a TTY (manual invocation).
        $stdin = defined('STDIN') ? STDIN : null;
        if (!$stdin) return [];
        $meta = @stream_get_meta_data($stdin);
        if (function_exists('stream_isatty') && @stream_isatty($stdin)) return [];
        if (isset($meta['seekable']) && $meta['seekable'] === false) {
            // pipe — safe to read
        }
        // Read with a soft cap to avoid hanging on a pathological pipe.
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

    private function detectHostApp(): ?string
    {
        $cwd = getcwd() ?: '';
        // Walk up to find a sibling .claude/ marker.
        for ($i = 0; $i < 6; $i++) {
            if (is_dir($cwd . DIRECTORY_SEPARATOR . '.claude')) {
                $base = basename($cwd);
                if ($base) return strtolower($base);
                break;
            }
            $parent = dirname($cwd);
            if ($parent === $cwd) break;
            $cwd = $parent;
        }
        return null;
    }
}
