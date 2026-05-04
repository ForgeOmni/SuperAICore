<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Guidance;

use PHPUnit\Framework\TestCase;
use SuperAICore\Guidance\Proof\Envelope;
use SuperAICore\Guidance\Proof\ProofChain;

final class ProofChainTest extends TestCase
{
    public function test_sha256_matches_known_value(): void
    {
        $this->assertSame(
            'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9',
            ProofChain::sha256Hex('hello world'),
        );
    }

    public function test_hmac_sha256_deterministic(): void
    {
        $a = ProofChain::hmacSha256Hex('key', 'message');
        $b = ProofChain::hmacSha256Hex('key', 'message');
        $this->assertSame($a, $b);
    }

    public function test_content_hash_key_order_independent(): void
    {
        $a = ProofChain::contentHashSorted('{"b": 2, "a": 1}');
        $b = ProofChain::contentHashSorted('{"a": 1, "b": 2}');
        $this->assertSame($a, $b);
    }

    public function test_content_hash_nested_key_order_independent(): void
    {
        $a = ProofChain::contentHashSorted('{"z": {"b": 2, "a": 1}, "y": [3, 2, 1]}');
        $b = ProofChain::contentHashSorted('{"y": [3, 2, 1], "z": {"a": 1, "b": 2}}');
        $this->assertSame($a, $b);
    }

    public function test_content_hash_falls_back_for_non_json(): void
    {
        $h = ProofChain::contentHashSorted('not-json{{{');
        $this->assertSame(ProofChain::sha256Hex('not-json{{{'), $h);
    }

    public function test_chain_append_links_to_genesis(): void
    {
        $key = 'shared-secret';
        $env = ProofChain::appendEnvelope(
            previous: null,
            envelopeId: 'e1',
            runEventId: 'r1',
            timestamp: '2026-05-04T00:00:00Z',
            contentPayload: ['k' => 'v1'],
            signingKey: $key,
        );
        $this->assertSame(ProofChain::GENESIS, $env->previousHash);
        $this->assertNotEmpty($env->signature);
        $this->assertTrue(ProofChain::verifyChain([$env], $key));
    }

    public function test_chain_links_subsequent_envelopes(): void
    {
        $key = 'k';
        $e1 = ProofChain::appendEnvelope(null, 'e1', 'r1', '2026-05-04T00:00:00Z',
            ['step' => 1], $key);
        $e2 = ProofChain::appendEnvelope($e1, 'e2', 'r1', '2026-05-04T00:00:01Z',
            ['step' => 2], $key);
        $e3 = ProofChain::appendEnvelope($e2, 'e3', 'r1', '2026-05-04T00:00:02Z',
            ['step' => 3], $key);

        $this->assertSame($e1->contentHash, $e2->previousHash);
        $this->assertSame($e2->contentHash, $e3->previousHash);
        $this->assertTrue(ProofChain::verifyChain([$e1, $e2, $e3], $key));
    }

    public function test_verify_rejects_tampered_signature(): void
    {
        $key = 'k';
        $e1 = ProofChain::appendEnvelope(null, 'e1', 'r1', 'ts', ['x' => 1], $key);
        $tampered = $e1->withSignature(str_repeat('0', 64));
        $this->assertFalse(ProofChain::verifyChain([$tampered], $key));
    }

    public function test_verify_rejects_broken_link(): void
    {
        $key = 'k';
        $e1 = ProofChain::appendEnvelope(null, 'e1', 'r1', 'ts1', ['x' => 1], $key);
        // Manually fabricate e2 that points at the wrong previousHash.
        $bad = ProofChain::appendEnvelope(
            previous: null, // genesis instead of e1
            envelopeId: 'e2',
            runEventId: 'r1',
            timestamp: 'ts2',
            contentPayload: ['x' => 2],
            signingKey: $key,
        );
        $this->assertFalse(ProofChain::verifyChain([$e1, $bad], $key));
    }

    public function test_envelope_round_trips_through_array(): void
    {
        $key = 'k';
        $e = ProofChain::appendEnvelope(null, 'e1', 'r1', 'ts', ['data' => 'value'], $key);
        $roundtrip = Envelope::fromArray($e->toArray());

        $this->assertSame($e->envelopeId, $roundtrip->envelopeId);
        $this->assertSame($e->contentHash, $roundtrip->contentHash);
        $this->assertSame($e->signature, $roundtrip->signature);
        $this->assertTrue(ProofChain::verifyChain([$roundtrip], $key));
    }

    public function test_wrong_signing_key_invalidates_chain(): void
    {
        $e = ProofChain::appendEnvelope(null, 'e1', 'r1', 'ts', ['x' => 1], 'right-key');
        $this->assertTrue(ProofChain::verifyChain([$e], 'right-key'));
        $this->assertFalse(ProofChain::verifyChain([$e], 'wrong-key'));
    }
}
