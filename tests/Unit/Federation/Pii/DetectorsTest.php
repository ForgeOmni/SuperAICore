<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit\Federation\Pii;

use PHPUnit\Framework\TestCase;
use SuperAICore\Federation\Pii\Detectors\AwsKeyDetector;
use SuperAICore\Federation\Pii\Detectors\CreditCardDetector;
use SuperAICore\Federation\Pii\Detectors\EmailDetector;
use SuperAICore\Federation\Pii\Detectors\JwtDetector;
use SuperAICore\Federation\Pii\Detectors\PrivateKeyDetector;
use SuperAICore\Federation\Pii\Detectors\SsnDetector;

/**
 * One small focused test per detector. Each test asserts (a) a known
 * positive sample is found, (b) a likely false-positive is rejected.
 */
final class DetectorsTest extends TestCase
{
    public function test_email_finds_addresses(): void
    {
        $det = new EmailDetector();
        $matches = $det->detect('Contact alice@example.com or bob+tag@sub.co.uk for details.');
        $values = array_map(fn ($m) => $m->value, $matches);
        $this->assertContains('alice@example.com', $values);
        $this->assertContains('bob+tag@sub.co.uk', $values);
    }

    public function test_email_rejects_at_in_path(): void
    {
        $det = new EmailDetector();
        // No TLD, just `@/` — shouldn't match.
        $matches = $det->detect('Path: /home/user@/file.txt');
        $this->assertSame([], $matches);
    }

    public function test_credit_card_luhn_valid_match(): void
    {
        $det = new CreditCardDetector();
        // Test card from Stripe's documentation: 4242 4242 4242 4242 (Luhn-valid)
        $matches = $det->detect('Charge to 4242 4242 4242 4242 today.');
        $this->assertCount(1, $matches);
        $this->assertSame('4242 4242 4242 4242', $matches[0]->value);
    }

    public function test_credit_card_rejects_non_luhn(): void
    {
        $det = new CreditCardDetector();
        // 16 digits but bad Luhn — order numbers, IDs, etc.
        $matches = $det->detect('Order 1234567890123456 was placed.');
        $this->assertSame([], $matches);
    }

    public function test_ssn_accepts_valid_pattern(): void
    {
        $det = new SsnDetector();
        $matches = $det->detect('SSN 123-45-6789 on file.');
        $this->assertCount(1, $matches);
        $this->assertSame('123-45-6789', $matches[0]->value);
    }

    public function test_ssn_rejects_invalid_area_codes(): void
    {
        $det = new SsnDetector();
        // 666 area + 900+ area + 000 group + 0000 serial — all should miss.
        $hay = '666-12-3456 999-12-3456 123-00-3456 123-45-0000';
        $this->assertSame([], $det->detect($hay));
    }

    public function test_aws_access_key_id(): void
    {
        $det = new AwsKeyDetector();
        $matches = $det->detect('Use AKIAIOSFODNN7EXAMPLE in env.');
        $this->assertCount(1, $matches);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $matches[0]->value);
        $this->assertSame('aws_access_key', $matches[0]->detectorName);
    }

    public function test_aws_secret_access_key_anchored(): void
    {
        $det = new AwsKeyDetector();
        $haystack = 'aws_secret_access_key=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
        $matches = $det->detect($haystack);
        $names = array_map(fn ($m) => $m->detectorName, $matches);
        $this->assertContains('aws_secret_access_key', $names);
    }

    public function test_aws_secret_not_flagged_standalone(): void
    {
        $det = new AwsKeyDetector();
        // 40-char base64-ish without the keyword anchor — should NOT match.
        $matches = $det->detect('hash: wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        // Standalone match would be high false-positive — we don't ship this.
        $this->assertEmpty(array_filter($matches, fn ($m) => $m->detectorName === 'aws_secret_access_key'));
    }

    public function test_jwt_detected(): void
    {
        $det = new JwtDetector();
        // Header: {"alg":"HS256","typ":"JWT"}, payload: {"sub":"x"}, sig: junk
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ4In0.signature_blob_here';
        $matches = $det->detect("Auth: Bearer {$jwt}");
        $this->assertCount(1, $matches);
        $this->assertSame($jwt, $matches[0]->value);
    }

    public function test_jwt_rejects_random_3_segments(): void
    {
        $det = new JwtDetector();
        // Three base64-ish segments but the header doesn't decode to valid JWT JSON.
        $matches = $det->detect('Use abc12345.def67890.ghijklmn for ID');
        $this->assertSame([], $matches);
    }

    public function test_private_key_pem_block(): void
    {
        $det = new PrivateKeyDetector();
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAOEX...redacted\n-----END RSA PRIVATE KEY-----";
        $matches = $det->detect("Config:\n{$pem}\nDone.");
        $this->assertCount(1, $matches);
        $this->assertStringStartsWith('-----BEGIN', $matches[0]->value);
        $this->assertStringEndsWith('-----', $matches[0]->value);
    }

    public function test_private_key_openssh_block(): void
    {
        $det = new PrivateKeyDetector();
        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAA\n-----END OPENSSH PRIVATE KEY-----";
        $this->assertCount(1, $det->detect($pem));
    }
}
