<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\CliSkillBridge;

/**
 * Pins the generation-dependent bridge surface for `kimi`: both CLI
 * generations natively discover a skills dir of SKILL.md packs — the
 * descriptor only swaps WHICH dir per the installed layout
 * (~/.kimi-code/skills for kimi-code, ~/.kimi/skills for legacy
 * kimi-cli, whose native discovery was re-verified against v1.49.0).
 * Other backends must pass through the static BACKENDS entry untouched.
 */
final class CliSkillBridgeDescriptorTest extends TestCase
{
    public function test_kimi_descriptor_is_native_dir_on_legacy_layout(): void
    {
        $desc = $this->withSandboxHome(['.kimi'], fn () => (new CliSkillBridge())->descriptor('kimi'));

        $this->assertSame('native_dir', $desc['mode']);
        $this->assertSame('.kimi/skills', $desc['dir']);
        $this->assertSame('super-team-', $desc['prefix']);
    }

    public function test_kimi_descriptor_is_native_dir_on_kimi_code_layout(): void
    {
        $desc = $this->withSandboxHome(['.kimi-code'], fn () => (new CliSkillBridge())->descriptor('kimi'));

        $this->assertSame('native_dir', $desc['mode']);
        $this->assertSame('.kimi-code/skills', $desc['dir']);
        $this->assertSame('super-team-', $desc['prefix']);
    }

    public function test_non_kimi_backends_pass_through_static_entry(): void
    {
        $bridge = new CliSkillBridge();
        $this->assertSame(CliSkillBridge::BACKENDS['codex'], $bridge->descriptor('codex'));
        $this->assertSame(CliSkillBridge::BACKENDS['claude'], $bridge->descriptor('claude'));
        $this->assertNull($bridge->descriptor('mystery-engine'));
    }

    /** @param string[] $dirs */
    private function withSandboxHome(array $dirs, callable $fn): mixed
    {
        $sandbox = sys_get_temp_dir() . '/kimi-bridge-' . bin2hex(random_bytes(3));
        mkdir($sandbox, 0755, true);
        foreach ($dirs as $d) {
            mkdir($sandbox . '/' . $d, 0755, true);
        }
        $prev = getenv('HOME');
        putenv('HOME=' . $sandbox);
        try {
            return $fn();
        } finally {
            putenv($prev === false ? 'HOME' : 'HOME=' . $prev);
            foreach ($dirs as $d) {
                @rmdir($sandbox . '/' . $d);
            }
            @rmdir($sandbox);
        }
    }
}
