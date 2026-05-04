<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii\Detectors;

use SuperAICore\Federation\Pii\DetectionMatch;
use SuperAICore\Federation\Pii\Detector;

/**
 * PEM private-key block detector. Catches the full
 * `-----BEGIN ... PRIVATE KEY-----` ... `-----END ... PRIVATE KEY-----`
 * envelope (RSA / DSA / EC / ED25519 / OPENSSH / generic).
 *
 * Always paired with `Policy::BLOCK` in default policy maps — leaking a
 * private key downstream is never recoverable, so the pipeline refuses
 * the entire message rather than redacting and continuing.
 */
final class PrivateKeyDetector implements Detector
{
    public function name(): string { return 'private_key'; }
    public function category(): string { return 'Private Key'; }

    public function detect(string $haystack): array
    {
        $out = [];
        if (!preg_match_all(
            '/-----BEGIN (?:RSA |DSA |EC |OPENSSH |ENCRYPTED |)PRIVATE KEY-----[\s\S]+?-----END (?:RSA |DSA |EC |OPENSSH |ENCRYPTED |)PRIVATE KEY-----/',
            $haystack,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return $out;
        }
        foreach ($matches[0] as [$value, $offset]) {
            $out[] = new DetectionMatch($this->name(), $value, $offset, strlen($value));
        }
        return $out;
    }
}
