<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii;

/**
 * Detect → policy-route → rewrite. The single entry point host code
 * uses to scrub messages before they leave the local trust boundary.
 *
 * Construction:
 *
 *   $pipeline = new PiiPipeline(
 *       detectors: PiiPipeline::defaultDetectors(),
 *       policyMap: PiiPipeline::defaultPolicyMap(TrustLevel::UNTRUSTED),
 *   );
 *
 *   $result = $pipeline->scrub($text);
 *   if ($result->blocked) { audit + drop; return; }
 *   $sender->send($result->text);
 *
 * Per-trust-level policy maps live in `defaultPolicyMap()` — host apps
 * can swap in their own table by passing an explicit `policyMap`.
 *
 * Replacement strategy is byte-precise: matches are sorted by offset,
 * applied right-to-left so earlier offsets stay valid through the
 * rewrite. Overlapping matches (same offset) are deduped — first
 * detector to claim a span wins; the others are dropped from the
 * applied-actions log to avoid double-reporting.
 */
final class PiiPipeline
{
    /**
     * @param Detector[] $detectors
     * @param array<string, Policy> $policyMap detector name → policy
     * @param Policy $defaultPolicy applied when a detector has no entry in the map
     */
    public function __construct(
        private readonly array $detectors,
        private readonly array $policyMap,
        private readonly Policy $defaultPolicy = Policy::REDACT,
    ) {}

    public function scrub(string $text): PipelineResult
    {
        // 1. Run every detector.
        /** @var DetectionMatch[] $all */
        $all = [];
        foreach ($this->detectors as $det) {
            foreach ($det->detect($text) as $m) {
                $all[] = $m;
            }
        }
        if ($all === []) {
            return PipelineResult::processed($text, []);
        }

        // 2. Sort by offset asc, then length desc so longer matches win
        // when two detectors fire at the same start.
        usort($all, function (DetectionMatch $a, DetectionMatch $b) {
            return $a->offset <=> $b->offset ?: $b->length <=> $a->length;
        });

        // 3. Drop matches whose span overlaps an earlier accepted match.
        $accepted = [];
        $cursor = -1;
        foreach ($all as $m) {
            if ($m->offset < $cursor) continue; // overlap — skip
            $accepted[] = $m;
            $cursor = $m->offset + $m->length;
        }

        // 4. Resolve policy per match. Block-policy short-circuits.
        $actions = [];
        $blocked = false;
        foreach ($accepted as $m) {
            $policy = $this->policyFor($m->detectorName);
            if ($policy === Policy::BLOCK) {
                $blocked = true;
                $actions[] = new AppliedAction(
                    detectorName: $m->detectorName,
                    policy: $policy,
                    offset: $m->offset,
                    originalLength: $m->length,
                    replacement: '',
                );
            } elseif ($policy === Policy::PASS) {
                // Don't record — pipeline only logs deviations from input.
                continue;
            } else {
                $replacement = $policy === Policy::HASH
                    ? '[HASH:' . substr(hash('sha256', $m->value), 0, 16) . ']'
                    : '[REDACTED:' . $m->detectorName . ']';
                $actions[] = new AppliedAction(
                    detectorName: $m->detectorName,
                    policy: $policy,
                    offset: $m->offset,
                    originalLength: $m->length,
                    replacement: $replacement,
                );
            }
        }

        if ($blocked) {
            return PipelineResult::blocked($text, $actions);
        }

        // 5. Apply rewrites right-to-left so earlier offsets remain valid.
        $rewritten = $text;
        foreach (array_reverse($actions) as $a) {
            $rewritten = substr($rewritten, 0, $a->offset)
                       . $a->replacement
                       . substr($rewritten, $a->offset + $a->originalLength);
        }

        return PipelineResult::processed($rewritten, $actions);
    }

    private function policyFor(string $detectorName): Policy
    {
        return $this->policyMap[$detectorName] ?? $this->defaultPolicy;
    }

    /**
     * The 6 detectors we ship out-of-the-box.
     *
     * @return Detector[]
     */
    public static function defaultDetectors(): array
    {
        return [
            new Detectors\PrivateKeyDetector(),
            new Detectors\AwsKeyDetector(),
            new Detectors\JwtDetector(),
            new Detectors\CreditCardDetector(),
            new Detectors\SsnDetector(),
            new Detectors\EmailDetector(),
        ];
    }

    /**
     * Reasonable default policy table for each trust tier. Hosts that
     * want stricter or looser routing pass their own map to the ctor.
     *
     * Rules:
     *   - `private_key` always BLOCK regardless of tier — leaking it is
     *     unrecoverable.
     *   - `aws_*` BLOCK below TRUSTED, REDACT at TRUSTED, PASS at PRIVILEGED.
     *   - `jwt` REDACT below TRUSTED, PASS above (tokens have TTL).
     *   - `credit_card` / `ssn` HASH for VERIFIED+ (lets receivers join on
     *     them without revealing); REDACT for UNTRUSTED.
     *   - `email` REDACT for UNTRUSTED, PASS otherwise.
     *
     * @return array<string, Policy>
     */
    public static function defaultPolicyMap(TrustLevel $tier): array
    {
        return [
            'private_key'           => Policy::BLOCK,

            'aws_access_key'        => $tier->atLeast(TrustLevel::PRIVILEGED) ? Policy::PASS
                                       : ($tier->atLeast(TrustLevel::TRUSTED) ? Policy::REDACT : Policy::BLOCK),
            'aws_secret_access_key' => $tier->atLeast(TrustLevel::PRIVILEGED) ? Policy::PASS
                                       : ($tier->atLeast(TrustLevel::TRUSTED) ? Policy::REDACT : Policy::BLOCK),

            'jwt'                   => $tier->atLeast(TrustLevel::TRUSTED) ? Policy::PASS : Policy::REDACT,

            'credit_card'           => $tier->atLeast(TrustLevel::VERIFIED) ? Policy::HASH : Policy::REDACT,
            'ssn'                   => $tier->atLeast(TrustLevel::VERIFIED) ? Policy::HASH : Policy::REDACT,

            'email'                 => $tier->atLeast(TrustLevel::VERIFIED) ? Policy::PASS : Policy::REDACT,
        ];
    }
}
