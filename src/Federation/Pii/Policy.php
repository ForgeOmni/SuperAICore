<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii;

/**
 * What to do with a detected PII match.
 *
 *   BLOCK  — refuse the whole message; pipeline returns
 *            `PipelineResult::blocked()` and callers should not
 *            forward it. Used for the highest-sensitivity matches
 *            (private keys, AWS root credentials).
 *   REDACT — replace the match with `[REDACTED:<detectorName>]` so
 *            the receiver knows something was scrubbed without
 *            knowing what. Default for most detectors against
 *            UNTRUSTED peers.
 *   HASH   — replace the match with `[HASH:<sha256-prefix>]` so two
 *            occurrences of the same value collide (useful for
 *            joining tables across federation without revealing
 *            either side's raw values).
 *   PASS   — leave the match alone. Default for TRUSTED peers and
 *            for low-sensitivity detectors.
 */
enum Policy: string
{
    case BLOCK  = 'block';
    case REDACT = 'redact';
    case HASH   = 'hash';
    case PASS   = 'pass';
}
