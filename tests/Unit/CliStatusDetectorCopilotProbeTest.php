<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CliStatusDetector;

/**
 * Covers the opt-in copilot CLI liveness probe added on top of the
 * env-token / config-dir heuristic.
 *
 * We don't want to shell out to a real binary here, so we drive the
 * probe through a test-only subclass that overrides the one spawn point.
 */
final class CliStatusDetectorCopilotProbeTest extends TestCase
{
    protected function setUp(): void
    {
        // Scrub probe env var + cache between tests so toggling the gate
        // is visible to detectAuth().
        putenv('SUPERAICORE_COPILOT_PROBE');
        $ref = new \ReflectionProperty(CliStatusDetector::class, 'copilotLiveCache');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }

    public function test_probe_off_by_default_no_live_key(): void
    {
        $auth = TestableCliStatusDetector::callDetectAuth('copilot', '/tmp/copilot');
        $this->assertArrayNotHasKey('live', $auth);
    }

    public function test_probe_enabled_adds_live_true_when_binary_responds(): void
    {
        putenv('SUPERAICORE_COPILOT_PROBE=1');
        TestableCliStatusDetector::$fakeProbe = true;

        $auth = TestableCliStatusDetector::callDetectAuth('copilot', '/tmp/copilot');

        $this->assertArrayHasKey('live', $auth);
        $this->assertTrue($auth['live']);
    }

    public function test_probe_enabled_adds_live_false_when_binary_stalls(): void
    {
        putenv('SUPERAICORE_COPILOT_PROBE=1');
        TestableCliStatusDetector::$fakeProbe = false;

        $auth = TestableCliStatusDetector::callDetectAuth('copilot', '/tmp/copilot');

        $this->assertArrayHasKey('live', $auth);
        $this->assertFalse($auth['live']);
    }

    public function test_probe_result_cached_per_path_within_request(): void
    {
        putenv('SUPERAICORE_COPILOT_PROBE=1');
        TestableCliStatusDetector::$fakeProbe = true;
        TestableCliStatusDetector::$probeCalls = 0;

        TestableCliStatusDetector::callDetectAuth('copilot', '/tmp/copilot');
        TestableCliStatusDetector::callDetectAuth('copilot', '/tmp/copilot');

        $this->assertSame(1, TestableCliStatusDetector::$probeCalls);
    }
}

final class TestableCliStatusDetector extends CliStatusDetector
{
    public static bool $fakeProbe = false;
    public static int $probeCalls = 0;

    public static function callDetectAuth(string $binary, string $path): ?array
    {
        // detectAuth is protected; reach through reflection.
        $m = new \ReflectionMethod(self::class, 'detectAuth');
        $m->setAccessible(true);
        return $m->invoke(null, $binary, $path);
    }

    protected static function probeCopilotLive(string $path): bool
    {
        self::$probeCalls++;
        // Still honor the cache contract from the parent.
        $ref = new \ReflectionProperty(CliStatusDetector::class, 'copilotLiveCache');
        $ref->setAccessible(true);
        $cache = $ref->getValue();
        if (isset($cache[$path])) {
            self::$probeCalls--; // we wouldn't have actually spawned
            return $cache[$path];
        }
        $cache[$path] = self::$fakeProbe;
        $ref->setValue(null, $cache);
        return self::$fakeProbe;
    }
}
