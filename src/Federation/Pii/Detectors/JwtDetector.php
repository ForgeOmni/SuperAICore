<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii\Detectors;

use SuperAICore\Federation\Pii\DetectionMatch;
use SuperAICore\Federation\Pii\Detector;

/**
 * JWT detector. Three base64url segments separated by dots. We decode
 * the header to verify it's actually a JWT (random `a.b.c` text doesn't
 * trigger), keeping precision high.
 */
final class JwtDetector implements Detector
{
    public function name(): string { return 'jwt'; }
    public function category(): string { return 'JSON Web Token'; }

    public function detect(string $haystack): array
    {
        $out = [];
        if (!preg_match_all(
            '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]+\b/',
            $haystack,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return $out;
        }
        foreach ($matches[0] as [$value, $offset]) {
            // Verify the header decodes to JSON with `alg`/`typ`.
            $parts = explode('.', $value);
            $head = self::base64UrlDecode($parts[0]);
            if ($head === null) continue;
            $decoded = json_decode($head, true);
            if (!is_array($decoded)) continue;
            if (!isset($decoded['alg']) && !isset($decoded['typ'])) continue;
            $out[] = new DetectionMatch($this->name(), $value, $offset, strlen($value));
        }
        return $out;
    }

    private static function base64UrlDecode(string $s): ?string
    {
        $s = strtr($s, '-_', '+/');
        $padded = $s . str_repeat('=', (4 - strlen($s) % 4) % 4);
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}
