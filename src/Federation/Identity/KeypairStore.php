<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Identity;

/**
 * Persists an `Ed25519Keypair` at a known on-disk location.
 *
 * Default path: `<home>/.superaicore/federation/identity.json`
 *
 * On-disk format:
 *   {
 *     "version": 1,
 *     "created_at": "2026-05-04T12:34:56+00:00",
 *     "fingerprint": "ab12cd34...",
 *     "public_key_hex":  "...",
 *     "secret_key_hex":  "..."
 *   }
 *
 * The secret_key_hex IS the private key; the file is chmod 0600 on
 * platforms that respect it. We deliberately store hex (not raw bytes)
 * so `cat identity.json` is greppable and the file survives line-ending
 * normalization on Windows / network shares.
 *
 * The store is single-keypair-per-host today. Multi-identity support
 * (one key per federation realm) can layer on top by storing under
 * `<home>/.superaicore/federation/identities/<realm>.json` with a
 * trivial wrapper — postponed until we have an actual realm split.
 */
final class KeypairStore
{
    public const VERSION = 1;

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * Load the persisted keypair, or null if the file is missing /
     * unreadable / unparseable.
     */
    public function load(): ?Ed25519Keypair
    {
        if (!is_file($this->path)) return null;
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        $pub = $data['public_key_hex'] ?? null;
        $sec = $data['secret_key_hex'] ?? null;
        if (!is_string($pub) || !is_string($sec)) return null;
        try {
            return Ed25519Keypair::fromHex($pub, $sec);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Load existing keypair, or generate + persist a fresh one if none
     * exists. Idempotent — same identity survives across processes.
     */
    public function loadOrCreate(): Ed25519Keypair
    {
        $existing = $this->load();
        if ($existing !== null) return $existing;

        $fresh = Ed25519Keypair::generate();
        $this->save($fresh);
        return $fresh;
    }

    public function save(Ed25519Keypair $kp): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $payload = [
            'version'         => self::VERSION,
            'created_at'      => date('c'),
            'fingerprint'     => $kp->fingerprint(),
            'public_key_hex'  => $kp->publicKeyHex(),
            'secret_key_hex'  => $kp->secretKeyHex(),
        ];

        $tmp = $this->path . '.tmp';
        @file_put_contents($tmp,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        @chmod($tmp, 0600);
        @rename($tmp, $this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Default path under the user's home dir. On Windows uses USERPROFILE,
     * elsewhere uses HOME. Falls back to cwd if neither is set (CI-safe).
     */
    public static function defaultPath(): string
    {
        $home = (string) ($_SERVER['HOME'] ?? getenv('USERPROFILE') ?: getenv('HOME') ?: '.');
        return rtrim($home, '/\\') . DIRECTORY_SEPARATOR
             . '.superaicore' . DIRECTORY_SEPARATOR
             . 'federation' . DIRECTORY_SEPARATOR
             . 'identity.json';
    }
}
