<?php

namespace SuperAICore\Runner;

use SuperAICore\Registry\Agent;

/**
 * Executes a resolved Agent against some backend CLI. The agent body is
 * the system prompt; the task comes from the caller.
 */
interface AgentRunner
{
    public function runAgent(Agent $agent, string $task, bool $dryRun): int;
}
