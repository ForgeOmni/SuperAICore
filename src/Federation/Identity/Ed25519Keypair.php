<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Identity;

/**
 * Ed25519 keypair used for federation identity.
 *
 * Two on-disk formats supported:
 *
 *   - **OpenSSH-style raw bytes** (32-byte secret + 32-byte public key
 *     concatenated, libsodium's native shape). What we generate +
 *     persist via `KeypairStore`.
 *   - **Hex** (64-char public, 128-char secret). Convenient for env
 *     vars / config files / federation handshake payloads.
 *
 * `signMessage()` / `verifyMessage()` produce / consume detached
 * signatures (64 bytes). Always use detached signatures over inlined —
 * federation messages are JSON envelopes that need to round-trip
 * unchanged through intermediaries.
 *
 * Borrowed in spirit from ruflo's `@noble/ed25519` + ed25519
 * challenge-response, ported to PHP's libsodium binding so we don't
 * depend on Node.
 */
final class Ed25519Keypair
{
    public const PUBLIC_KEY_BYTES = SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES; // 32
    public const SECRET_KEY_BYTES = SODIUM_CRYPTO_SIGN_SECRETKEYBYTES; // 64
    public const SIGNATURE_BYTES  = SODIUM_CRYPTO_SIGN_BYTES;          // 64

    /**
     * @param string $publicKey 32 raw bytes
     * @param string $secretKey 64 raw bytes (NOT hex)
     */
    public function __construct(
        private readonly string $publicKey,
        private readonly string $secretKey,
    ) {
        if (strlen($publicKey) !== self::PUBLIC_KEY_BYTES) {
            throw new \InvalidArgumentException(
                'public key must be ' . self::PUBLIC_KEY_BYTES . ' bytes, got ' . strlen($publicKey)
            );
        }
        if (strlen($secretKey) !== self::SECRET_KEY_BYTES) {
            throw new \InvalidArgumentException(
                'secret key must be ' . self::SECRET_KEY_BYTES . ' bytes, got ' . strlen($secretKey)
            );
        }
    }

    public static function generate(): self
    {
        $kp = sodium_crypto_sign_keypair();
        return new self(
            publicKey: sodium_crypto_sign_publickey($kp),
            secretKey: sodium_crypto_sign_secretkey($kp),
        );
    }

    /**
     * Reconstruct a keypair from a 32-byte seed. Deterministic — the
     * same seed produces the same keypair. Useful for tests and for
     * derivable identity (e.g. seed = HKDF(master_secret, peer_id)).
     */
    public static function fromSeed(string $seed): self
    {
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \InvalidArgumentException(
                'seed must be ' . SODIUM_CRYPTO_SIGN_SEEDBYTES . ' bytes'
            );
        }
        $kp = sodium_crypto_sign_seed_keypair($seed);
        return new self(
            publicKey: sodium_crypto_sign_publickey($kp),
            secretKey: sodium_crypto_sign_secretkey($kp),
        );
    }

    public static function fromHex(string $publicKeyHex, string $secretKeyHex): self
    {
        return new self(
            publicKey: sodium_hex2bin($publicKeyHex),
            secretKey: sodium_hex2bin($secretKeyHex),
        );
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    public function publicKeyHex(): string
    {
        return sodium_bin2hex($this->publicKey);
    }

    public function secretKeyHex(): string
    {
        return sodium_bin2hex($this->secretKey);
    }

    /**
     * Stable, short fingerprint of the public key — first 16 hex chars
     * of the sha256. Matches the convention many federation protocols
     * use for human-readable peer IDs.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->publicKey), 0, 16);
    }

    /**
     * Sign a message; returns a 64-byte detached signature.
     */
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    /**
     * Verify a detached signature against a message and a public key.
     * Static so callers can verify peer messages without holding a
     * full keypair locally.
     */
    public static function verify(string $signature, string $message, string $publicKey): bool
    {
        if (strlen($signature) !== self::SIGNATURE_BYTES) return false;
        if (strlen($publicKey) !== self::PUBLIC_KEY_BYTES) return false;
        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }
}
