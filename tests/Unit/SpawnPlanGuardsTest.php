<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\AgentSpawn\SpawnPlan;

/**
 * Host-side task_prompt guard injection, added after RUN 68/69 (2026-04-22)
 * where Gemini Flash ignored the backend-preamble guard clauses that told
 * it to inject the 4 rules into each child's task_prompt. The host-side
 * append is bullet-proof against weak models: every child receives the
 * guards regardless of which model composed the plan.
 */
final class SpawnPlanGuardsTest extends TestCase
{
    public function test_chinese_task_prompt_gets_chinese_guards_with_name_substitution(): void
    {
        $out = SpawnPlan::appendGuards(
            '任务：分析加拿大市场。',
            'regional-khanna'
        );

        $this->assertStringContainsString(SpawnPlan::GUARD_MARKER, $out);
        // Chinese-version 4-rule markers (shortened 2026-04-22)
        $this->assertStringContainsString('角色边界', $out);
        $this->assertStringContainsString('不写整合', $out);
        $this->assertStringContainsString('语言一致', $out);
        $this->assertStringContainsString('扩展名只有', $out);
        // Richness directive (added 2026-04-22 after RUN 71 quality drop)
        $this->assertStringContainsString('丰富度要求', $out);
        // Agent name substituted
        $this->assertStringContainsString('regional-khanna', $out);
        $this->assertStringNotContainsString('__AGENT_NAME__', $out);
        // Original task_prompt preserved
        $this->assertStringContainsString('任务：分析加拿大市场。', $out);
    }

    public function test_english_task_prompt_gets_english_guards(): void
    {
        $out = SpawnPlan::appendGuards(
            'Task: analyze the Canadian market entry prospects.',
            'ceo-bezos'
        );

        $this->assertStringContainsString(SpawnPlan::GUARD_MARKER, $out);
        $this->assertStringContainsString('Stay in your lane', $out);
        $this->assertStringContainsString('No consolidation', $out);
        $this->assertStringContainsString('Language uniformity', $out);
        $this->assertStringContainsString('Extensions:', $out);
        $this->assertStringContainsString('Be rich', $out);
        $this->assertStringContainsString('ceo-bezos', $out);
        $this->assertStringNotContainsString('__AGENT_NAME__', $out);
        // Should NOT mix in Chinese phrases
        $this->assertStringNotContainsString('角色边界', $out);
    }

    public function test_idempotent_second_call_does_not_double_append(): void
    {
        $once = SpawnPlan::appendGuards('任务：X', 'agent-a');
        $twice = SpawnPlan::appendGuards($once, 'agent-a');
        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, SpawnPlan::GUARD_MARKER));
    }

    public function test_mixed_language_prompt_with_any_cjk_picks_chinese_guards(): void
    {
        $out = SpawnPlan::appendGuards(
            'Task: analyze 加拿大 market.',
            'market-rascoff'
        );
        $this->assertStringContainsString('角色边界', $out);
        $this->assertStringNotContainsString('Stay in your lane', $out);
    }

    public function test_empty_task_prompt_still_receives_guards(): void
    {
        $out = SpawnPlan::appendGuards('', 'agent-x');
        $this->assertStringContainsString(SpawnPlan::GUARD_MARKER, $out);
        $this->assertStringContainsString('Stay in your lane', $out);
        $this->assertStringContainsString('agent-x', $out);
    }

    public function test_strips_inline_critical_output_rule_before_appending_guards(): void
    {
        // Simulate Gemini's typical first-pass emission: the task_prompt
        // embeds its own CRITICAL OUTPUT RULE pointing at a Chinese
        // output_subdir. When the host overrides output_subdir to
        // canonical English (see fromFile), the embedded rule becomes
        // stale — it must be stripped so ChildRunner's appended rule is
        // the single source of truth.
        $tp = "任务：分析加拿大市场。CRITICAL OUTPUT RULE: All files you create MUST be written inside /path/首席执行官/ using write_file. Only create .md, .csv, or .png files. Never write outside that directory.";
        $out = SpawnPlan::appendGuards($tp, 'ceo-bezos');

        $this->assertStringNotContainsString('CRITICAL OUTPUT RULE', $out,
            'the stale CRITICAL OUTPUT RULE must be stripped');
        $this->assertStringNotContainsString('首席执行官', $out,
            'the stale Chinese output_subdir path must be gone');
        $this->assertStringContainsString('任务：分析加拿大市场。', $out,
            'the task description must be preserved');
        $this->assertStringContainsString(SpawnPlan::GUARD_MARKER, $out);
    }

    public function test_forces_output_subdir_to_canonical_name_regardless_of_plan(): void
    {
        // Gemini Flash sometimes emits a localized output_subdir (`首席
        // 执行官` instead of `ceo-bezos`). Non-ASCII paths trip up Flash
        // during the consolidation re-call — it hallucinates "no output
        // files found" — so the host ignores the model's preference and
        // always uses `name` as the subdir.
        $tmp = sys_get_temp_dir() . '/sac-plan-subdir-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($tmp, json_encode([
            'version' => 1,
            'concurrency' => 2,
            'agents' => [
                ['name' => 'ceo-bezos', 'task_prompt' => 'Task: x', 'output_subdir' => '首席执行官'],
                ['name' => 'cfo-munger', 'task_prompt' => 'Task: y'],   // no output_subdir at all
            ],
        ]));

        $plan = SpawnPlan::fromFile($tmp);
        @unlink($tmp);

        $this->assertNotNull($plan);
        $this->assertSame('ceo-bezos',  $plan->agents[0]['output_subdir'],
            'localized output_subdir must be overridden with canonical name');
        $this->assertSame('cfo-munger', $plan->agents[1]['output_subdir'],
            'missing output_subdir must default to canonical name');
    }

    public function test_fromFile_auto_injects_guards_into_every_agent(): void
    {
        // End-to-end: round-trip through fromFile() to confirm the guard
        // injection happens as part of plan loading, not just when
        // `appendGuards` is called manually.
        $tmp = sys_get_temp_dir() . '/sac-plan-guards-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($tmp, json_encode([
            'version' => 1,
            'concurrency' => 2,
            'agents' => [
                ['name' => 'regional-khanna', 'task_prompt' => '任务：测试', 'output_subdir' => 'regional-khanna'],
                ['name' => 'ceo-bezos',       'task_prompt' => 'Task: test', 'output_subdir' => 'ceo-bezos'],
            ],
        ]));

        $plan = SpawnPlan::fromFile($tmp);
        @unlink($tmp);

        $this->assertNotNull($plan);
        $this->assertCount(2, $plan->agents);

        $zh = $plan->agents[0];
        $this->assertStringContainsString('角色边界', $zh['task_prompt']);
        $this->assertStringContainsString('regional-khanna', $zh['task_prompt']);

        $en = $plan->agents[1];
        $this->assertStringContainsString('Stay in your lane', $en['task_prompt']);
        $this->assertStringContainsString('ceo-bezos', $en['task_prompt']);
    }
}
