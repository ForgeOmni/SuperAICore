<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Federation\Identity;

use PHPUnit\Framework\TestCase;
use SuperAICore\Federation\Identity\Ed25519Keypair;
use SuperAICore\Federation\Identity\KeypairStore;

final class Ed25519KeypairTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-ed25519-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function test_generate_yields_correct_key_sizes(): void
    {
        $kp = Ed25519Keypair::generate();
        $this->assertSame(32, strlen($kp->publicKey()));
        $this->assertSame(64, strlen($kp->secretKey()));
        $this->assertSame(64, strlen($kp->publicKeyHex()));
        $this->assertSame(128, strlen($kp->secretKeyHex()));
    }

    public function test_seed_keypair_is_deterministic(): void
    {
        $seed = str_repeat("\x42", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $kp1 = Ed25519Keypair::fromSeed($seed);
        $kp2 = Ed25519Keypair::fromSeed($seed);
        $this->assertSame($kp1->publicKeyHex(), $kp2->publicKeyHex());
        $this->assertSame($kp1->secretKeyHex(), $kp2->secretKeyHex());
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $kp = Ed25519Keypair::generate();
        $msg = 'federation-handshake-2026-05-04T12:00:00Z';
        $sig = $kp->sign($msg);
        $this->assertSame(64, strlen($sig));
        $this->assertTrue(Ed25519Keypair::verify($sig, $msg, $kp->publicKey()));
    }

    public function test_verify_rejects_tampered_message(): void
    {
        $kp = Ed25519Keypair::generate();
        $sig = $kp->sign('original');
        $this->assertFalse(Ed25519Keypair::verify($sig, 'TAMPERED', $kp->publicKey()));
    }

    public function test_verify_rejects_wrong_pubkey(): void
    {
        $kp1 = Ed25519Keypair::generate();
        $kp2 = Ed25519Keypair::generate();
        $sig = $kp1->sign('msg');
        $this->assertFalse(Ed25519Keypair::verify($sig, 'msg', $kp2->publicKey()));
    }

    public function test_verify_rejects_truncated_signature(): void
    {
        $kp = Ed25519Keypair::generate();
        $sig = $kp->sign('msg');
        $this->assertFalse(Ed25519Keypair::verify(substr($sig, 0, 32), 'msg', $kp->publicKey()));
    }

    public function test_fingerprint_is_stable_and_short(): void
    {
        $kp = Ed25519Keypair::generate();
        $fp = $kp->fingerprint();
        $this->assertSame(16, strlen($fp));
        $this->assertSame($fp, $kp->fingerprint(), 'idempotent');
    }

    public function test_invalid_key_size_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ed25519Keypair('too-short', str_repeat("\0", 64));
    }

    public function test_keypair_store_load_or_create_persists(): void
    {
        $store = new KeypairStore($this->tmp . '/identity.json');
        $this->assertNull($store->load());

        $created = $store->loadOrCreate();
        $this->assertFileExists($this->tmp . '/identity.json');

        $reloaded = $store->load();
        $this->assertNotNull($reloaded);
        $this->assertSame($created->publicKeyHex(), $reloaded->publicKeyHex());
        $this->assertSame($created->fingerprint(), $reloaded->fingerprint());
    }

    public function test_keypair_store_load_or_create_idempotent(): void
    {
        $store = new KeypairStore($this->tmp . '/identity.json');
        $kp1 = $store->loadOrCreate();
        $kp2 = $store->loadOrCreate();
        $this->assertSame($kp1->publicKeyHex(), $kp2->publicKeyHex());
    }

    public function test_keypair_store_returns_null_on_corrupt_file(): void
    {
        file_put_contents($this->tmp . '/identity.json', 'not-json{{');
        $store = new KeypairStore($this->tmp . '/identity.json');
        $this->assertNull($store->load());
    }

    private function rrm(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($p) ? $this->rrm($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
