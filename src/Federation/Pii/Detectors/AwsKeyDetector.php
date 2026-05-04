<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii\Detectors;

use SuperAICore\Federation\Pii\DetectionMatch;
use SuperAICore\Federation\Pii\Detector;

/**
 * AWS key detector — both halves of an AWS credential pair.
 *
 *   - Access key id: starts with `AKIA` (long-term) / `ASIA` (STS) /
 *     `AGPA` (group) / `AROA` (role) / `AIDA` (user) followed by 16
 *     uppercase alphanumerics. Exactly 20 chars total.
 *   - Secret access key: 40-char base64-ish (alphanumeric + `/+`),
 *     conventionally appearing in `AWS_SECRET_ACCESS_KEY=...` or quoted
 *     strings near an access-key-id. The 40-char shape has high false-
 *     positive risk on its own (random hex strings hit), so we anchor
 *     against `aws_secret`/`secret_access_key` keywords nearby OR
 *     require it to be quoted next to a previously-detected key id.
 *
 * For now we ship the high-precision access-key-id check + a
 * keyword-anchored secret check. Standalone 40-char strings are NOT
 * flagged — too noisy.
 */
final class AwsKeyDetector implements Detector
{
    public function name(): string { return 'aws_access_key'; }
    public function category(): string { return 'AWS Credential'; }

    public function detect(string $haystack): array
    {
        $out = [];

        // Access key id — high precision.
        if (preg_match_all(
            '/\b(?:AKIA|ASIA|AGPA|AROA|AIDA)[A-Z0-9]{16}\b/',
            $haystack,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            foreach ($matches[0] as [$value, $offset]) {
                $out[] = new DetectionMatch($this->name(), $value, $offset, strlen($value));
            }
        }

        // Secret access key — anchored to keyword. Catches the most common
        // env-var / yaml / json shapes:
        //   aws_secret_access_key=abc...
        //   AWS_SECRET_ACCESS_KEY: "abc..."
        //   "secretAccessKey": "abc..."
        if (preg_match_all(
            '/\b(?:aws[_\-]?secret[_\-]?access[_\-]?key|secret[_\-]?access[_\-]?key)\b\s*[:=]\s*["\']?([A-Za-z0-9\/+]{40})["\']?/i',
            $haystack,
            $smatches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        )) {
            foreach ($smatches as $m) {
                [$value, $offset] = $m[1];
                $out[] = new DetectionMatch('aws_secret_access_key', $value, $offset, strlen($value));
            }
        }

        usort($out, fn ($a, $b) => $a->offset <=> $b->offset);
        return $out;
    }
}
