<?php

declare(strict_types=1);

namespace SuperAICore\Guidance\Proof;

/**
 * One link in a proof chain. Field names match ruflo's TypeScript
 * `ProofEnvelope` exactly so a chain serialized by SuperAICore
 * round-trips through the same JS verifier.
 *
 * `signature` is HMAC-SHA256 hex over the canonical body (every field
 * except `signature` itself). `previousHash` is the previous envelope's
 * `contentHash`, or the 64-zero genesis for the first link.
 */
final class Envelope
{
    /**
     * @param array<string, mixed> $toolCallHashes
     * @param array<string, mixed> $memoryLineage
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $envelopeId,
        public readonly string $runEventId,
        public readonly string $timestamp,
        public readonly string $contentHash,
        public readonly string $previousHash,
        public readonly array $toolCallHashes,
        public readonly string $guidanceHash,
        public readonly array $memoryLineage,
        public readonly string $signature,
        public readonly array $metadata,
    ) {}

    public function withSignature(string $signature): self
    {
        return new self(
            $this->envelopeId,
            $this->runEventId,
            $this->timestamp,
            $this->contentHash,
            $this->previousHash,
            $this->toolCallHashes,
            $this->guidanceHash,
            $this->memoryLineage,
            $signature,
            $this->metadata,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'envelopeId'     => $this->envelopeId,
            'runEventId'     => $this->runEventId,
            'timestamp'      => $this->timestamp,
            'contentHash'    => $this->contentHash,
            'previousHash'   => $this->previousHash,
            'toolCallHashes' => $this->toolCallHashes,
            'guidanceHash'   => $this->guidanceHash,
            'memoryLineage'  => $this->memoryLineage,
            'signature'      => $this->signature,
            'metadata'       => $this->metadata,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            envelopeId: (string) ($row['envelopeId'] ?? ''),
            runEventId: (string) ($row['runEventId'] ?? ''),
            timestamp: (string) ($row['timestamp'] ?? ''),
            contentHash: (string) ($row['contentHash'] ?? ''),
            previousHash: (string) ($row['previousHash'] ?? ''),
            toolCallHashes: is_array($row['toolCallHashes'] ?? null) ? $row['toolCallHashes'] : [],
            guidanceHash: (string) ($row['guidanceHash'] ?? ''),
            memoryLineage: is_array($row['memoryLineage'] ?? null) ? $row['memoryLineage'] : [],
            signature: (string) ($row['signature'] ?? ''),
            metadata: is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
        );
    }
}
