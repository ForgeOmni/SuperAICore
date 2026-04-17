<?php

namespace SuperAICore\AgentSpawn;

use SuperAICore\Services\GeminiModelResolver;
use Symfony\Component\Process\Process;

/**
 * Launches one gemini-cli child as the isolated runtime for a sub-agent.
 * Combines the agent's system prompt + task prompt into a single prompt
 * (gemini CLI has no separate system/user slots in non-interactive mode),
 * then streams via `cat prompt.md | gemini --prompt "" --yolo -o stream-json`.
 */
class GeminiChildRunner implements ChildRunner
{
    public function __construct(
        protected string $binary = 'gemini',
    ) {}

    public function build(
        array $agent,
        string $outputRoot,
        string $logFile,
        string $projectRoot,
        array $env = [],
        ?string $model = null,
    ): Process {
        $resolvedModel = GeminiModelResolver::resolve($model);

        $prompt = trim($agent['system_prompt']) . "\n\n---\n\n" . trim($agent['task_prompt']);
        $prompt .= "\n\n---\n\nCRITICAL OUTPUT RULE: All files you create MUST be written inside "
            . rtrim($outputRoot, DIRECTORY_SEPARATOR) . '/' . $agent['output_subdir']
            . " using write_file. Only create .md, .csv, or .png files. Never write outside that directory.\n";

        $promptFile = str_replace('.log', '.prompt.md', $logFile);
        @file_put_contents($promptFile, $prompt);

        $flags = ['--prompt', '', '--yolo', '-o', 'stream-json'];
        if ($resolvedModel) {
            $flags[] = '--model';
            $flags[] = $resolvedModel;
        }
        $escapedFlags = implode(' ', array_map(fn ($p) => escapeshellarg($p), $flags));

        $shellCmd = "cat \"{$promptFile}\" | \"{$this->binary}\" {$escapedFlags} > \"{$logFile}\" 2>&1";
        $execScript = str_replace('.log', '-exec.sh', $logFile);
        $scriptContent = "#!/bin/sh\ncd \"{$projectRoot}\"\n{$shellCmd}\n";
        file_put_contents($execScript, $scriptContent);
        chmod($execScript, 0755);

        $process = new Process(['sh', $execScript], $projectRoot);
        $process->setEnv($env ?: getenv());
        $process->setTimeout(3600);
        $process->setIdleTimeout(900);
        return $process;
    }
}
