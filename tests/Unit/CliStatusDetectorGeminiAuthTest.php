<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CliStatusDetector;

/**
 * Covers the Gemini branch of detectAuth(). Gemini has no `auth status`
 * subcommand, so we probe ~/.gemini/oauth_creds.json (OAuth) and env-vars
 * (API key) the same way SuperAgent\Auth\GeminiCliCredentials does.
 */
final class CliStatusDetectorGeminiAuthTest extends TestCase
{
    private string $tmpHome;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/saicore-gemini-auth-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome . '/.gemini', 0755, true);

        // Scrub overrides so detectGeminiAuth() reads from the tmp HOME.
        putenv('GEMINI_API_KEY');
        putenv('GOOGLE_API_KEY');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpHome);
    }

    public function test_oauth_creds_file_reports_logged_in_with_oauth_method(): void
    {
        file_put_contents(
            $this->tmpHome . '/.gemini/oauth_creds.json',
            json_encode([
                'access_token' => 'ya29.fake',
                'refresh_token' => 'refresh.fake',
                'expires_at' => (time() + 3600) * 1000,
            ])
        );

        $auth = $this->invoke(['HOME' => $this->tmpHome]);

        $this->assertTrue($auth['loggedIn']);
        $this->assertSame('oauth', $auth['method']);
        $this->assertNotNull($auth['expires_at']);
    }

    public function test_env_api_key_without_file_reports_api_key_method(): void
    {
        $auth = $this->invoke([
            'HOME' => $this->tmpHome,
            'GEMINI_API_KEY' => 'AIzaFake',
        ]);

        $this->assertTrue($auth['loggedIn']);
        $this->assertSame('api_key', $auth['method']);
        $this->assertSame('env-key', $auth['status']);
    }

    public function test_no_file_and_no_env_reports_not_logged_in(): void
    {
        $auth = $this->invoke(['HOME' => $this->tmpHome]);

        $this->assertFalse($auth['loggedIn']);
        $this->assertSame('not-logged-in', $auth['status']);
        $this->assertNull($auth['method']);
    }

    private function invoke(array $env): array
    {
        $m = new \ReflectionMethod(CliStatusDetector::class, 'detectGeminiAuth');
        $m->setAccessible(true);
        return $m->invoke(null, $env);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($path);
    }
}
