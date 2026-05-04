<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii\Detectors;

use SuperAICore\Federation\Pii\DetectionMatch;
use SuperAICore\Federation\Pii\Detector;

/**
 * US SSN detector. Pattern: `AAA-GG-SSSS` (with optional spaces or
 * hyphens between the three groups, or no separator).
 *
 * SSA invalid ranges filtered: AAA must be 001–665 or 667–899
 * (666 and 900+ never assigned); GG must be 01–99; SSSS must be 0001–9999.
 * This is the SSA's published policy and rules out roughly half of all
 * naive 9-digit matches.
 */
final class SsnDetector implements Detector
{
    public function name(): string { return 'ssn'; }
    public function category(): string { return 'US Social Security Number'; }

    public function detect(string $haystack): array
    {
        $out = [];
        if (!preg_match_all(
            '/\b(\d{3})[ \-]?(\d{2})[ \-]?(\d{4})\b/',
            $haystack,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        )) {
            return $out;
        }
        foreach ($matches as $m) {
            [$full, $offset] = $m[0];
            $area = (int) $m[1][0];
            $group = (int) $m[2][0];
            $serial = (int) $m[3][0];

            if ($area === 0 || $area === 666 || $area >= 900) continue;
            if ($group === 0) continue;
            if ($serial === 0) continue;

            $out[] = new DetectionMatch($this->name(), $full, $offset, strlen($full));
        }
        return $out;
    }
}
