<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii\Detectors;

use SuperAICore\Federation\Pii\DetectionMatch;
use SuperAICore\Federation\Pii\Detector;

/**
 * Email address detector. Uses RFC-5322-ish pattern that prefers
 * precision over completeness — false positives are worse than missing
 * the long tail because every false positive becomes a confusing
 * `[REDACTED:email]` in the wire output. Common shapes covered:
 * `local@host.tld`, `local+tag@host`, IDN-free hosts.
 */
final class EmailDetector implements Detector
{
    private const PATTERN = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i';

    public function name(): string { return 'email'; }
    public function category(): string { return 'Email Address'; }

    public function detect(string $haystack): array
    {
        $out = [];
        if (!preg_match_all(self::PATTERN, $haystack, $matches, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($matches[0] as [$value, $offset]) {
            $out[] = new DetectionMatch($this->name(), $value, $offset, strlen($value));
        }
        return $out;
    }
}
