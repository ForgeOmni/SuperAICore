<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Federation\Pii;

use PHPUnit\Framework\TestCase;
use SuperAICore\Federation\Pii\PiiPipeline;
use SuperAICore\Federation\Pii\Policy;
use SuperAICore\Federation\Pii\TrustLevel;

final class PiiPipelineTest extends TestCase
{
    public function test_untrusted_redacts_email_and_blocks_aws_key(): void
    {
        $pipeline = new PiiPipeline(
            detectors: PiiPipeline::defaultDetectors(),
            policyMap: PiiPipeline::defaultPolicyMap(TrustLevel::UNTRUSTED),
        );

        $r = $pipeline->scrub('Email alice@example.com about AKIAIOSFODNN7EXAMPLE');
        $this->assertTrue($r->blocked, 'AWS key under UNTRUSTED tier should BLOCK');
    }

    public function test_untrusted_redacts_email_alone(): void
    {
        $pipeline = new PiiPipeline(
            detectors: [new \SuperAICore\Federation\Pii\Detectors\EmailDetector()],
            policyMap: ['email' => Policy::REDACT],
        );
        $r = $pipeline->scrub('Reach me at alice@example.com');
        $this->assertFalse($r->blocked);
        $this->assertStringContainsString('[REDACTED:email]', $r->text);
        $this->assertStringNotContainsString('alice@example.com', $r->text);
        $this->assertCount(1, $r->actions);
        $this->assertSame(Policy::REDACT, $r->actions[0]->policy);
    }

    public function test_hash_policy_collides_same_value(): void
    {
        $pipeline = new PiiPipeline(
            detectors: [new \SuperAICore\Federation\Pii\Detectors\EmailDetector()],
            policyMap: ['email' => Policy::HASH],
        );
        $r = $pipeline->scrub('a@x.com a@x.com b@x.com');
        // Two identical emails should produce identical [HASH:...] tokens.
        preg_match_all('/\[HASH:([a-f0-9]{16})\]/', $r->text, $hashes);
        $this->assertCount(3, $hashes[1]);
        $this->assertSame($hashes[1][0], $hashes[1][1]);
        $this->assertNotSame($hashes[1][0], $hashes[1][2]);
    }

    public function test_pass_policy_leaves_text_unchanged(): void
    {
        $pipeline = new PiiPipeline(
            detectors: [new \SuperAICore\Federation\Pii\Detectors\EmailDetector()],
            policyMap: ['email' => Policy::PASS],
        );
        $r = $pipeline->scrub('alice@example.com is fine');
        $this->assertFalse($r->blocked);
        $this->assertSame('alice@example.com is fine', $r->text);
        $this->assertSame([], $r->actions, 'PASS should not produce action records');
    }

    public function test_blocked_returns_original_text(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nABC\n-----END RSA PRIVATE KEY-----";
        $original = "Config: {$pem}";
        $pipeline = new PiiPipeline(
            detectors: PiiPipeline::defaultDetectors(),
            policyMap: PiiPipeline::defaultPolicyMap(TrustLevel::TRUSTED),
        );
        $r = $pipeline->scrub($original);
        $this->assertTrue($r->blocked);
        // Original text returned as-is so callers can audit what would have leaked.
        $this->assertSame($original, $r->text);
    }

    public function test_overlapping_matches_dedupe(): void
    {
        // Synthesize a contrived case where two detectors hit the same span.
        // Real-world example: CC# embedded inside an SSN-shaped string is
        // implausible, so mock it with a stub detector pair.
        $hay = 'value 123-45-6789 here';

        $pipeline = new PiiPipeline(
            detectors: [new \SuperAICore\Federation\Pii\Detectors\SsnDetector()],
            policyMap: ['ssn' => Policy::REDACT],
        );
        $r = $pipeline->scrub($hay);

        $this->assertStringContainsString('[REDACTED:ssn]', $r->text);
        $this->assertStringNotContainsString('123-45-6789', $r->text);
    }

    public function test_multiple_matches_rewritten_in_correct_order(): void
    {
        $pipeline = new PiiPipeline(
            detectors: [new \SuperAICore\Federation\Pii\Detectors\EmailDetector()],
            policyMap: ['email' => Policy::REDACT],
        );
        $r = $pipeline->scrub('first@a.com middle text second@b.com end');
        // Both should be redacted, surrounding text preserved.
        $this->assertStringContainsString('middle text', $r->text);
        $this->assertStringContainsString(' end', $r->text);
        $this->assertSame(2, substr_count($r->text, '[REDACTED:email]'));
    }

    public function test_default_policy_map_changes_with_tier(): void
    {
        $emailHay = 'reach alice@example.com';

        $untrusted = new PiiPipeline(
            detectors: PiiPipeline::defaultDetectors(),
            policyMap: PiiPipeline::defaultPolicyMap(TrustLevel::UNTRUSTED),
        );
        $verified = new PiiPipeline(
            detectors: PiiPipeline::defaultDetectors(),
            policyMap: PiiPipeline::defaultPolicyMap(TrustLevel::VERIFIED),
        );

        $this->assertStringNotContainsString('alice@example.com', $untrusted->scrub($emailHay)->text);
        $this->assertStringContainsString('alice@example.com', $verified->scrub($emailHay)->text);
    }

    public function test_clean_text_unchanged(): void
    {
        $pipeline = new PiiPipeline(
            detectors: PiiPipeline::defaultDetectors(),
            policyMap: PiiPipeline::defaultPolicyMap(TrustLevel::UNTRUSTED),
        );
        $r = $pipeline->scrub('Just a normal message with no PII at all.');
        $this->assertFalse($r->blocked);
        $this->assertSame('Just a normal message with no PII at all.', $r->text);
        $this->assertSame([], $r->actions);
    }

    public function test_trust_level_atleast_ordering(): void
    {
        $this->assertTrue(TrustLevel::PRIVILEGED->atLeast(TrustLevel::TRUSTED));
        $this->assertTrue(TrustLevel::TRUSTED->atLeast(TrustLevel::VERIFIED));
        $this->assertFalse(TrustLevel::UNTRUSTED->atLeast(TrustLevel::VERIFIED));
        $this->assertTrue(TrustLevel::UNTRUSTED->atLeast(TrustLevel::UNTRUSTED));
    }
}
