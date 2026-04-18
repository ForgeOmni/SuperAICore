<?php

namespace SuperAICore\Runner;

use SuperAICore\Models\AiProcess;
use SuperAICore\Registry\Skill;
use SuperAICore\Support\ProcessRegistrar;
use Symfony\Component\Process\Process;

/**
 * Pipes a skill body to `copilot -p '...' -s --allow-all-tools` and streams
 * combined stdout/stderr back to the caller.
 *
 * No translation: Copilot CLI scans `.claude/skills/` itself, so when a skill
 * body references its own name or other skills they resolve natively. We
 * still send the full body (rather than just "run the X skill") to match the
 * other runners' contract — args are appended in an <args> block and the
 * skill's already-rendered prompt is what reaches the model.
 */
final class CopilotSkillRunner implements SkillRunner
{
    public function __construct(
        private readonly string $binary = 'copilot',
        private readonly bool $allowAllTools = true,
        private readonly ?\Closure $writer = null,
    ) {}

    public function runSkill(Skill $skill, array $args, bool $dryRun): int
    {
        $prompt = $skill->body;
        if ($args) {
            $prompt .= "\n\n<args>\n" . implode("\n", $args) . "\n</args>\n";
        }

        $cmd = [$this->binary, '-p', $prompt, '-s'];
        if ($this->allowAllTools) {
            $cmd[] = '--allow-all-tools';
        }
        if ($skill->allowedTools) {
            // Copilot expresses tool grants via repeated --allow-tool flags
            // with category(glob) syntax. The skill's `allowedTools` are
            // canonical (Claude) names, which don't translate 1:1 — for MVP-1
            // we rely on --allow-all-tools and just surface a note. MVP-2
            // adds CopilotToolPermissions for proper translation.
            $this->emit("[note] allowed-tools declared (" . implode(',', $skill->allowedTools) . ") — passed via --allow-all-tools for now; per-tool translation lands in MVP-2.\n");
        }

        if ($dryRun) {
            $this->emit('[dry-run] ' . $this->binary . " -p <skill:{$skill->name} body + " . count($args) . ' args> -s' . ($this->allowAllTools ? ' --allow-all-tools' : '') . "\n");
            return 0;
        }

        // start() first so we capture the PID before wait() blocks; the
        // monitor needs the PID to mark the row alive/dead via posix_kill(0).
        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->start();

        $logFile = ProcessRegistrar::defaultLogPath('copilot', "skill-{$skill->name}");
        $logFh = ProcessRegistrar::openLog($logFile);
        $procRow = ProcessRegistrar::start(
            backend: 'copilot',
            pid: (int) $process->getPid(),
            command: $this->binary . " -p <skill:{$skill->name}> -s",
            logFile: $logFile,
            externalLabel: "skill:{$skill->name}",
            metadata: ['kind' => 'skill', 'skill_name' => $skill->name],
        );

        $exit = $process->wait(function ($type, $buffer) use ($logFh) {
            $this->emit($buffer);
            if ($logFh) @fwrite($logFh, $buffer);
        });

        if ($logFh) @fclose($logFh);
        ProcessRegistrar::end($procRow, $exit === 0 ? AiProcess::STATUS_FINISHED : AiProcess::STATUS_FAILED);

        return $exit;
    }

    private function emit(string $chunk): void
    {
        if ($this->writer) {
            ($this->writer)($chunk);
        } else {
            fwrite(\STDOUT, $chunk);
        }
    }
}
