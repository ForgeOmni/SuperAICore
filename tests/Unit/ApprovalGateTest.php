<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Runner\ApprovalGate;
use SuperAICore\Runner\ApprovalMode;
use SuperAICore\Tests\TestCase;

class ApprovalGateTest extends TestCase
{
    // ── Mode parsing ─────────────────────────────────────────────

    public function test_parse_aliases_for_auto(): void
    {
        $this->assertSame(ApprovalMode::Auto, ApprovalMode::parse('auto'));
        $this->assertSame(ApprovalMode::Auto, ApprovalMode::parse('YOLO'));
        $this->assertSame(ApprovalMode::Auto, ApprovalMode::parse('full-auto'));
    }

    public function test_parse_aliases_for_never(): void
    {
        $this->assertSame(ApprovalMode::Never, ApprovalMode::parse('never'));
        $this->assertSame(ApprovalMode::Never, ApprovalMode::parse('readonly'));
        $this->assertSame(ApprovalMode::Never, ApprovalMode::parse('read-only'));
    }

    public function test_parse_unknown_falls_back_to_suggest(): void
    {
        // Safest default — never silently auto-approve a misconfigured
        // value. Codex does the same.
        $this->assertSame(ApprovalMode::Suggest, ApprovalMode::parse('moonbeam'));
        $this->assertSame(ApprovalMode::Suggest, ApprovalMode::parse(null));
        $this->assertSame(ApprovalMode::Suggest, ApprovalMode::parse(''));
    }

    // ── Auto mode ────────────────────────────────────────────────

    public function test_auto_mode_allows_ordinary_tools(): void
    {
        $gate = new ApprovalGate();
        $d = $gate->evaluate('agent_write', ['path' => 'a.txt'], ApprovalMode::Auto);
        $this->assertTrue($d->approved);
    }

    public function test_auto_mode_pauses_destructive_shell(): void
    {
        // Even auto mode requires explicit approval for destructive
        // shell. The DestructiveCommandScanner is a safety floor
        // beneath every mode (except an explicit kill switch which
        // we don't expose).
        $gate = new ApprovalGate();
        $d = $gate->evaluate(
            'agent_bash',
            ['command' => 'rm -rf /var/data'],
            ApprovalMode::Auto,
        );
        $this->assertFalse($d->approved);
        $this->assertTrue($d->canRetry);
        $this->assertSame('destructive_pending_approval', $d->code);
    }

    // ── Suggest mode ─────────────────────────────────────────────

    public function test_suggest_mode_lets_read_only_tools_through(): void
    {
        $gate = new ApprovalGate();
        $d = $gate->evaluate('agent_grep', ['pattern' => 'foo'], ApprovalMode::Suggest);
        $this->assertTrue($d->approved);
    }

    public function test_suggest_mode_pauses_mutations(): void
    {
        $gate = new ApprovalGate();
        $d = $gate->evaluate('agent_write', ['path' => 'a.txt'], ApprovalMode::Suggest);
        $this->assertFalse($d->approved);
        $this->assertTrue($d->canRetry);
        $this->assertSame('mutation_pending_approval', $d->code);
    }

    public function test_suggest_mode_destructive_gets_distinct_code(): void
    {
        // UI uses `code` to pick a banner color / icon. A destructive
        // hit must NOT collapse into the generic mutation category.
        $gate = new ApprovalGate();
        $d = $gate->evaluate(
            'agent_bash',
            ['command' => 'git reset --hard origin/main'],
            ApprovalMode::Suggest,
        );
        $this->assertSame('destructive_pending_approval', $d->code);
    }

    // ── Never mode ───────────────────────────────────────────────

    public function test_never_mode_allows_read_only(): void
    {
        $gate = new ApprovalGate();
        $d = $gate->evaluate('agent_read', ['path' => 'a.txt'], ApprovalMode::Never);
        $this->assertTrue($d->approved);
    }

    public function test_never_mode_hard_denies_mutations(): void
    {
        $gate = new ApprovalGate();
        $d = $gate->evaluate('agent_write', ['path' => 'a.txt'], ApprovalMode::Never);
        $this->assertFalse($d->approved);
        // Hard-deny: there's no /approve recovery. User must flip mode.
        $this->assertFalse($d->canRetry);
        $this->assertSame('mode_disallows', $d->code);
    }

    // ── /approve override ────────────────────────────────────────

    public function test_user_override_lets_matching_tool_use_id_through(): void
    {
        // The /approve flow: user reads the suggested-approval reason,
        // decides to allow it, host re-issues the same tool_use_id with
        // the override flag set. Gate must let it through.
        $gate = new ApprovalGate();
        $d = $gate->evaluate(
            toolName: 'agent_write',
            arguments: ['path' => 'a.txt'],
            mode: ApprovalMode::Suggest,
            toolUseId: 'call-abc',
            approvedToolUseId: 'call-abc',
        );
        $this->assertTrue($d->approved);
        $this->assertStringContainsString('one-shot override', $d->reason);
    }

    public function test_user_override_does_not_apply_to_other_tool_use_ids(): void
    {
        // One-shot — must not approve a DIFFERENT call by accident.
        $gate = new ApprovalGate();
        $d = $gate->evaluate(
            toolName: 'agent_write',
            arguments: ['path' => 'a.txt'],
            mode: ApprovalMode::Suggest,
            toolUseId: 'call-xyz',
            approvedToolUseId: 'call-abc',
        );
        $this->assertFalse($d->approved);
    }

    public function test_user_override_can_unblock_destructive_in_never_mode(): void
    {
        // Even Never mode yields to an explicit override — that's the
        // whole point of /approve. Gate doesn't second-guess the user.
        $gate = new ApprovalGate();
        $d = $gate->evaluate(
            toolName: 'agent_bash',
            arguments: ['command' => 'rm -rf cache/'],
            mode: ApprovalMode::Never,
            toolUseId: 'call-1',
            approvedToolUseId: 'call-1',
        );
        $this->assertTrue($d->approved);
    }
}
