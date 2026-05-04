<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii;

/**
 * Five-tier trust model. Mirrors ruflo's federation trust ladder
 * (UNTRUSTED → VERIFIED → ATTESTED → TRUSTED → PRIVILEGED), but
 * deliberately drops the "behavioral scoring" claims — this enum is a
 * declarative tier flag, not a score. Movement between tiers is the
 * host's call (e.g. "every peer that completes a clean mTLS handshake
 * gets VERIFIED; ops manually promote to TRUSTED").
 *
 * Pipelines map a tier → per-detector `Policy` so an UNTRUSTED peer
 * sees emails REDACTED while a TRUSTED peer sees them PASS.
 */
enum TrustLevel: string
{
    case UNTRUSTED  = 'untrusted';
    case VERIFIED   = 'verified';
    case ATTESTED   = 'attested';
    case TRUSTED    = 'trusted';
    case PRIVILEGED = 'privileged';

    /** Numeric ordering for "at least this trusted" checks. */
    public function rank(): int
    {
        return match ($this) {
            self::UNTRUSTED  => 0,
            self::VERIFIED   => 1,
            self::ATTESTED   => 2,
            self::TRUSTED    => 3,
            self::PRIVILEGED => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
