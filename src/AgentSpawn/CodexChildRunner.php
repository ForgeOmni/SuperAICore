<?php

namespace SuperAICore\AgentSpawn;

use SuperAICore\Services\CodexModelResolver;
use Symfony\Component\Process\Process;

/**
 * Launches one codex-cli child as the isolated runtime for a sub-agent.
 * Combines system + task prompts, streams via
 * `cat prompt.md | codex exec --json --full-auto --skip-git-repo-check -`.
 */
class CodexChildRunner implements ChildRunner
{
    public function __construct(
        protected string $binary = 'codex',
    ) {}

    public function build(
        array $agent,
        string $outputRoot,
        string $logFile,
        string $projectRoot,
        array $env = [],
        ?string $model = null,
    ): Process {
        $resolvedModel = CodexModelResolver::resolve($model, $this->binary);

        $prompt = trim($agent['system_prompt']) . "\n\n---\n\n" . trim($agent['task_prompt']);
        $prompt .= "\n\n---\n\nCRITICAL OUTPUT RULE: All files you create MUST be written inside "
            . rtrim($outputRoot, DIRECTORY_SEPARATOR) . '/' . $agent['output_subdir']
            . ". Only create .md, .csv, or .png files. Never write outside that directory.\n";

        $promptFile = str_replace('.log', '.prompt.md', $logFile);
        @file_put_contents($promptFile, $prompt);

        $lastMessage = str_replace('.log', '-last.txt', $logFile);

        $flags = [
            'exec', '--json', '--full-auto', '--skip-git-repo-check',
            '-C', $projectRoot,
            '-o', $lastMessage,
        ];
        if ($resolvedModel) {
            $flags[] = '-m';
            $flags[] = $resolvedModel;
        }
        $flags[] = '-';
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
