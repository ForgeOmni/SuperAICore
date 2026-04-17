<?php

namespace SuperAICore\AgentSpawn;

use Symfony\Component\Process\Process;

/**
 * Spawns one sub-agent run as a child CLI process. Implementations map
 * backend-specific invocation (gemini vs codex) to a common interface.
 */
interface ChildRunner
{
    /**
     * Build the Symfony Process for one agent — caller is responsible
     * for starting it and collecting the log/output files.
     *
     * @param  array  $agent   plan entry: [name, system_prompt, task_prompt, output_subdir]
     * @param  string $outputRoot  absolute path to the parent run's output dir
     * @param  string $logFile     absolute path where stream output should be written
     * @param  string $projectRoot CWD for the child process
     * @param  array  $env         extra env vars (e.g. API keys)
     * @param  string|null $model  model override
     */
    public function build(
        array $agent,
        string $outputRoot,
        string $logFile,
        string $projectRoot,
        array $env = [],
        ?string $model = null,
    ): Process;
}
