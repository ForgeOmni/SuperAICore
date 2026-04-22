<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperAICore\AgentSpawn\Orchestrator;

/**
 * Covers the post-fanout sanitizer added after RUN 68 (2026-04-22), where
 * Gemini Flash ignored the preamble guard clauses and produced:
 *   - non-whitelisted extensions (e.g. `generate_charts.py`)
 *   - sibling-role sub-dirs (`regional-khanna/ceo/`, `.../cfo/`, `.../marketing/`)
 *   - consolidator-reserved filenames inside an agent subdir
 *     (`摘要.md`, `思维导图.md`, `流程图.md`)
 *
 * `auditAgentOutput()` is a pure inspection — it never mutates disk; it just
 * returns a list of human-readable warnings so Pipeline/operators can decide
 * whether to re-dispatch the offending child instead of consolidating its
 * fabricated output.
 */
final class OrchestratorAuditTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-orch-audit-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp && is_dir($this->tmp)) {
            $this->rrmdir($this->tmp);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') as $file) {
            is_dir($file) ? $this->rrmdir($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function audit(string $subdir, string $agentName): array
    {
        $m = (new ReflectionClass(Orchestrator::class))->getMethod('auditAgentOutput');
        $m->setAccessible(true);
        return $m->invoke(null, $subdir, $agentName);
    }

    public function test_clean_agent_produces_no_warnings(): void
    {
        $sub = $this->tmp . '/cfo-munger';
        mkdir($sub);
        file_put_contents($sub . '/财务分析报告.md', '# 报告');
        file_put_contents($sub . '/财务模型数据.csv', 'A,B');
        mkdir($sub . '/_signals');
        file_put_contents($sub . '/_signals/cfo-munger.md', '信号');

        $this->assertSame([], $this->audit($sub, 'cfo-munger'));
    }

    public function test_flags_non_whitelisted_extension(): void
    {
        $sub = $this->tmp . '/cfo-munger';
        mkdir($sub);
        file_put_contents($sub . '/report.md', 'ok');
        file_put_contents($sub . '/generate_charts.py', 'print(1)');
        file_put_contents($sub . '/helper.sh', 'echo');

        $warnings = $this->audit($sub, 'cfo-munger');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('non-whitelisted extensions', $warnings[0]);
        $this->assertStringContainsString('generate_charts.py', $warnings[0]);
        $this->assertStringContainsString('helper.sh', $warnings[0]);
    }

    public function test_flags_consolidator_reserved_filenames(): void
    {
        $sub = $this->tmp . '/regional-khanna';
        mkdir($sub);
        file_put_contents($sub . '/分析报告.md', 'ok');
        file_put_contents($sub . '/摘要.md', 'wrong');
        file_put_contents($sub . '/思维导图.md', 'wrong');
        file_put_contents($sub . '/summary.md', 'wrong');

        $warnings = $this->audit($sub, 'regional-khanna');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('consolidator-reserved filenames', $warnings[0]);
        $this->assertStringContainsString('摘要.md', $warnings[0]);
        $this->assertStringContainsString('思维导图.md', $warnings[0]);
        $this->assertStringContainsString('summary.md', $warnings[0]);
    }

    public function test_flags_sibling_role_subdirectories(): void
    {
        $sub = $this->tmp . '/regional-khanna';
        mkdir($sub);
        file_put_contents($sub . '/报告.md', 'ok');
        // The RUN 68 failure — regional-khanna fabricated cross-agent reports
        // in its own sub-directories.
        foreach (['ceo', 'cfo', 'marketing', 'compliance'] as $role) {
            mkdir($sub . '/' . $role);
            file_put_contents($sub . "/{$role}/fake.md", 'nope');
        }
        // `_signals` and dot-prefixed dirs are allowed (meta / IAP).
        mkdir($sub . '/_signals');
        mkdir($sub . '/.cache');

        $warnings = $this->audit($sub, 'regional-khanna');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('sibling-role sub-directories', $warnings[0]);
        $this->assertStringContainsString('ceo', $warnings[0]);
        $this->assertStringContainsString('cfo', $warnings[0]);
        $this->assertStringContainsString('marketing', $warnings[0]);
        // `.cache` and `_signals` must NOT appear
        $this->assertStringNotContainsString('.cache', $warnings[0]);
        $this->assertStringNotContainsString('_signals', $warnings[0]);
    }

    public function test_agent_own_name_subdir_is_not_flagged(): void
    {
        // If an agent happens to make a subdir matching its own name, we
        // don't flag it (uncommon but legal — e.g. `.cache/myname/`).
        $sub = $this->tmp . '/fin-crypto-burniske';
        mkdir($sub);
        file_put_contents($sub . '/报告.md', 'ok');
        mkdir($sub . '/fin-crypto-burniske');
        file_put_contents($sub . '/fin-crypto-burniske/inner.md', 'ok');

        $this->assertSame([], $this->audit($sub, 'fin-crypto-burniske'));
    }

    public function test_all_three_violation_classes_stacked(): void
    {
        $sub = $this->tmp . '/ceo-bezos';
        mkdir($sub);
        file_put_contents($sub . '/report.md', 'ok');
        file_put_contents($sub . '/helper.py', 'x');       // bad ext
        file_put_contents($sub . '/flowchart.md', 'y');    // consolidator reserved
        mkdir($sub . '/marketing');                        // sibling role
        file_put_contents($sub . '/marketing/fake.md', 'z');

        $warnings = $this->audit($sub, 'ceo-bezos');
        $this->assertCount(3, $warnings);
    }
}
