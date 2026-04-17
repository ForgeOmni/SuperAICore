<?php

namespace SuperAICore\Runner;

use SuperAICore\Registry\Skill;

/**
 * Executes a resolved Skill against some backend CLI.
 * Phase 1 ships a Claude runner; codex/gemini runners land in Phase 1.5.
 */
interface SkillRunner
{
    /**
     * @param  string[]  $args   free-form user args passed after `--`
     * @return int               process exit code
     */
    public function runSkill(Skill $skill, array $args, bool $dryRun): int;
}
