<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii\Detectors;

use SuperAICore\Federation\Pii\DetectionMatch;
use SuperAICore\Federation\Pii\Detector;

/**
 * Credit-card-number detector. Two-stage:
 *
 *   1. Regex finds 13–19 digit runs (with optional spaces / hyphens
 *      every 4 digits — the way humans paste cards).
 *   2. Luhn check filters false positives. Without Luhn, every order
 *      number, ticket id, and tracking code gets flagged.
 *
 * Major issuer ranges aren't enforced — if it Luhn-validates and it's
 * 13–19 digits, treat it as a card. Issuer detection (Visa/MC/Amex/...)
 * is a downstream concern; this detector is binary.
 */
final class CreditCardDetector implements Detector
{
    public function name(): string { return 'credit_card'; }
    public function category(): string { return 'Credit Card Number'; }

    public function detect(string $haystack): array
    {
        $out = [];
        if (!preg_match_all(
            '/\b(?:\d[ \-]?){12,18}\d\b/',
            $haystack,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return $out;
        }
        foreach ($matches[0] as [$value, $offset]) {
            $digitsOnly = preg_replace('/\D/', '', $value);
            if (strlen($digitsOnly) < 13 || strlen($digitsOnly) > 19) continue;
            if (!self::luhnValid($digitsOnly)) continue;
            $out[] = new DetectionMatch($this->name(), $value, $offset, strlen($value));
        }
        return $out;
    }

    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }
}
