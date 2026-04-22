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

        // Language-aware instruction body. The output filenames (摘要.md / 思维导图.md /
        // 流程图.md) are fixed contract — downstream PPT/report renderers look for those
        // exact names. But the instruction language itself must match the language the
        // sub-agents wrote in, otherwise Flash bleeds English into section headers of
        // 摘要.md and the user sees "# Sub-Agent Consolidation Pass Executive Summary"
        // on top of Chinese body (RUN 71, 2026-04-22).
        $isChinese = self::looksChinese($plan);

        return $isChinese
            ? self::buildZh($list, $outputDir)
            : self::buildEn($list, $outputDir);
    }

    /**
     * Heuristic: if any agent's task_prompt contains CJK characters, treat the
     * whole run as Chinese. Lines up with SpawnPlan::appendGuards's detection.
     */
    private static function looksChinese(SpawnPlan $plan): bool
    {
        foreach ($plan->agents as $agent) {
            $tp = (string) ($agent['task_prompt'] ?? '');
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $tp) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function buildZh(string $list, string $outputDir): string
    {
        return <<<PROMPT
# 整合阶段（Consolidation）

所有子 agent 已跑完，产出在磁盘上。

## 子 agent 产出位置

{$list}

## 你的任务

1. 用 `read_file` / `glob` 读取**每一个** agent 子目录下的 `.md` / `.csv` / `.png` 文件。
2. **综合**而非堆砌：把各 agent 的洞察连起来、指出一致与分歧、在原始材料基础上做二阶思考。不是"各 agent 写了什么"的罗列。
3. 在 `{$outputDir}/` 下且**只**生成这三个文件（文件名必须完全一致）：
   - `摘要.md` — 执行摘要
   - `思维导图.md` — 每个 agent 调研的 markdown 标题树
   - `流程图.md` — 实际执行流的 Mermaid flowchart
4. **所有文本一律中文，section 标题必须中文**。禁止在中文报告里出现下列英文 section 标题 —— 必须用括号里的中文等价物：
   - `# Executive Summary` → `# 执行摘要`
   - `## Task Overview` → `## 任务概览`
   - `## Key Findings` → `## 关键发现`
   - `## Agreement` / `## Disagreement` → `## 一致点` / `## 分歧点`
   - `## Recommendations` → `## 建议`
   - `## Risks` → `## 风险`
   - `## Appendix` → `## 附录`
   - `## Warnings` → `## 警告`
   这是 hard constraint；违反会被 downstream renderer 识别为失败。GOFO EXPRESS、URL、公司名、技术术语等专有名词和数字保留原样，**但 section 标题本身必须是中文**。
5. 鼓励在 `摘要.md` 中引用子 agent 产出的 PNG 图表（`![](<agent-subdir>/<图表名>.png>`），让整合文档也有可视化内容。

不要再写 `_spawn_plan.json`。不要再 spawn 新的 agent。

**异常处理：不准自创文件名。** 如果子目录为空或文件解析失败，把说明和部分综合**写进 `摘要.md`**，顶部加 `## 警告` section。**严禁**创建 `Error_No_Agent_Outputs_Found.md`、`consolidation_failed.md` 等自己起的新文件名。
PROMPT;
    }

    private static function buildEn(string $list, string $outputDir): string
    {
        return <<<PROMPT
# Consolidation Pass

All sub-agents have finished running. Their outputs are on disk.

## Agent Outputs

{$list}

## Your Task

1. Read every agent's output files (`.md` / `.csv` / `.png`) from their subdir via `read_file` / `glob`.
2. **Synthesize** rather than stack: connect insights across agents, surface agreements and disagreements, produce second-order reasoning on top of raw material. Not a "what each agent wrote" list.
3. Produce these three files in `{$outputDir}/` — and ONLY these three (filenames exact):
   - `摘要.md` — executive summary (Task Overview, Key Findings, Agreement, Disagreement, Recommendations, Risks, Appendix)
   - `思维导图.md` — Markdown heading tree of each agent's investigation
   - `流程图.md` — Mermaid flowchart of the actual execution
4. Consider embedding child-generated PNG charts (`![](<agent-subdir>/<chart>.png)`) inside `摘要.md` so the consolidated doc carries visuals, not just text.

Do NOT write `_spawn_plan.json` again — this is the consolidation pass. Do NOT spawn new agents.

**Error handling: do NOT invent new filenames.** If an agent's subdir is empty, a file fails to parse, or a path is inaccessible, write the explanation INSIDE `摘要.md` under a clear `## ⚠️ Warnings` section at the top. NEVER create files like `Error_No_Agent_Outputs_Found.md` or `consolidation_failed.md` — they clutter the output dir and bypass the three-file contract. Even when only `摘要.md` is producible, still write it (with the error context); skip the other two only when truly impossible.
PROMPT;
    }
}
