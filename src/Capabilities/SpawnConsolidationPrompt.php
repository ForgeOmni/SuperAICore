<?php

namespace SuperAICore\Capabilities;

use SuperAICore\AgentSpawn\SpawnPlan;

/**
 * Phase 3 prompt template for the spawn-plan consolidation re-call.
 *
 * Lifted from SuperTeam's `ExecuteTask::runConsolidationPass()` so every
 * downstream host produces identical 摘要.md / 思维导图.md / 流程图.md
 * trees regardless of which CLI ran.
 *
 * Hosts that want a different file set or different consolidation
 * instructions should NOT extend this class — instead, build their own
 * consolidation prompt and feed it directly into `TaskRunner::run()`,
 * skipping `BackendCapabilities::consolidationPrompt()`.
 *
 * The output-language is intentionally English-titles + Chinese-content.
 * SuperTeam's user base reads Chinese; the file-name conventions
 * (`摘要.md` / `思维导图.md` / `流程图.md`) are part of the contract for
 * downstream PPT / report-rendering pipelines that look for those exact
 * filenames. Hosts with a different convention should override.
 */
final class SpawnConsolidationPrompt
{
    /**
     * @param array<int,array{name:string,exit:int,log:string,duration_ms:int,error:?string}> $report
     */
    public static function build(SpawnPlan $plan, array $report, string $outputDir): string
    {
        $reportByName = [];
        foreach ($report as $row) {
            if (isset($row['name'])) $reportByName[$row['name']] = $row;
        }

        $agentList = [];
        foreach ($plan->agents as $agent) {
            $name = $agent['name'];
            $row = $reportByName[$name] ?? null;
            $exitStr = $row && isset($row['exit']) ? "exit={$row['exit']}" : 'exit=?';
            $agentList[] = "- **{$name}** ({$exitStr}): outputs in `{$outputDir}/{$agent['output_subdir']}/`";
        }

        $list = implode("\n", $agentList);

        return <<<PROMPT
# Consolidation Pass

All sub-agents have finished running. Their outputs are on disk.

## Agent Outputs

{$list}

## Your Task

1. Read every agent's output files (`.md` / `.csv`) from their subdir via `read_file` / `glob`.
2. Synthesize findings — don't just concatenate; connect insights across agents and note agreements/disagreements.
3. Produce these files in `{$outputDir}/`:
   - `摘要.md` — executive summary (Task Overview, Key Findings, Agreement, Disagreement, Recommendations, Risks, Appendix)
   - `思维导图.md` — Markdown heading tree of each agent's investigation
   - `流程图.md` — Mermaid flowchart of the actual execution

Do NOT write `_spawn_plan.json` again — this is the consolidation pass. Do NOT spawn new agents.

PROMPT;
    }
}
