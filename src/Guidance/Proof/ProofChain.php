<?php

declare(strict_types=1);

namespace SuperAICore\Guidance\Proof;

/**
 * Pure-PHP port of ruflo's `wasm-kernel/src/proof.rs`.
 *
 * Three primitives:
 *
 *   - `sha256Hex()` / `hmacSha256Hex()` — thin wrappers over PHP's
 *     `hash()`. Same hex output as the Rust crate, byte-for-byte.
 *
 *   - `contentHashSorted()` — recursive key-sort then hash. Produces
 *     the same content hash regardless of the input JSON's key order,
 *     so two PHP services emitting the same logical envelope agree on
 *     the digest.
 *
 *   - `verifyChain()` — given a list of `Envelope` records and an
 *     HMAC signing key, walks the chain and confirms (a) every
 *     envelope's signature matches its body and (b) every envelope
 *     links to its predecessor's `contentHash` (genesis = 64 zeros).
 *
 * Wire format intentionally identical to the Rust crate's
 * `SerializedChain` / `Envelope` types so PHP and Node services can
 * interop on the same proof log.
 *
 * Wiring point: `ai_usage_logs` rows can be appended to a
 * proof chain so any later tampering with cost / token figures is
 * detectable — the chain breaks at the first edited row.
 */
final class ProofChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function sha256Hex(string $input): string
    {
        return hash('sha256', $input);
    }

    public static function hmacSha256Hex(string $key, string $input): string
    {
        return hash_hmac('sha256', $input, $key);
    }

    /**
     * Hash arbitrary JSON with sorted keys for determinism. Falls back
     * to hashing the raw bytes when the input is not valid JSON — same
     * lenient contract the Rust crate uses.
     */
    public static function contentHashSorted(string $jsonInput): string
    {
        $value = json_decode($jsonInput, true);
        if ($value === null && json_last_error() !== JSON_ERROR_NONE) {
            return self::sha256Hex($jsonInput);
        }
        $sorted = self::sortValue($value);
        $canonical = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return self::sha256Hex($jsonInput);
        }
        return self::sha256Hex($canonical);
    }

    /**
     * Verify a chain's signatures + linkage.
     *
     * @param Envelope[] $envelopes
     */
    public static function verifyChain(array $envelopes, string $signingKey): bool
    {
        foreach ($envelopes as $i => $env) {
            $body = self::envelopeSigningBody($env);
            $expected = self::hmacSha256Hex($signingKey, $body);
            if (!hash_equals($expected, $env->signature)) {
                return false;
            }
            if ($i === 0) {
                if ($env->previousHash !== self::GENESIS) return false;
            } else {
                if ($env->previousHash !== $envelopes[$i - 1]->contentHash) return false;
            }
        }
        return true;
    }

    /**
     * Build a fresh envelope: hashes the content, links to previous,
     * signs the canonical body. Caller persists the result.
     *
     * @param array<string, mixed> $contentPayload Arbitrary structured body to hash + sign
     * @param array<string, mixed> $metadata Free-form metadata included in the signing body
     */
    public static function appendEnvelope(
        ?Envelope $previous,
        string $envelopeId,
        string $runEventId,
        string $timestamp,
        array $contentPayload,
        string $signingKey,
        array $toolCallHashes = [],
        string $guidanceHash = '',
        array $memoryLineage = [],
        array $metadata = [],
    ): Envelope {
        $contentHash = self::contentHashSorted(json_encode($contentPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $previousHash = $previous === null ? self::GENESIS : $previous->contentHash;

        $env = new Envelope(
            envelopeId: $envelopeId,
            runEventId: $runEventId,
            timestamp: $timestamp,
            contentHash: $contentHash,
            previousHash: $previousHash,
            toolCallHashes: $toolCallHashes,
            guidanceHash: $guidanceHash,
            memoryLineage: $memoryLineage,
            signature: '',
            metadata: $metadata,
        );
        $body = self::envelopeSigningBody($env);
        return $env->withSignature(self::hmacSha256Hex($signingKey, $body));
    }

    /**
     * Canonical signing body — same JSON shape ruflo's Rust impl signs,
     * so a chain produced by either side validates on the other.
     */
    private static function envelopeSigningBody(Envelope $e): string
    {
        $body = [
            'envelopeId'     => $e->envelopeId,
            'runEventId'     => $e->runEventId,
            'timestamp'      => $e->timestamp,
            'contentHash'    => $e->contentHash,
            'previousHash'   => $e->previousHash,
            'toolCallHashes' => $e->toolCallHashes,
            'guidanceHash'   => $e->guidanceHash,
            'memoryLineage'  => $e->memoryLineage,
            'metadata'       => $e->metadata,
        ];
        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param mixed $value */
    private static function sortValue($value)
    {
        if (is_array($value)) {
            // Distinguish list vs object the JSON way: empty arrays
            // are treated as objects to match Rust's serde_json default.
            if (array_is_list($value)) {
                return array_map([self::class, 'sortValue'], $value);
            }
            ksort($value);
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sortValue($v);
            }
            return $out;
        }
        return $value;
    }
}
