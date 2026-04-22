<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\AgentSpawn\SpawnPlan;
use SuperAICore\Capabilities\Concerns\BackendCapabilitiesDefaults;
use SuperAICore\Contracts\BackendCapabilities;

/**
 * Phase E contract: a host-defined custom Capabilities can adopt
 * `BackendCapabilitiesDefaults` to inherit no-op implementations of
 * any post-freeze interface methods (currently spawnPreamble +
 * consolidationPrompt) without writing them itself.
 *
 * If a future release adds another method to `BackendCapabilities`,
 * the maintainer must add a default to the trait in the SAME release —
 * this test verifies the trait satisfies the interface today, and
 * future test changes will catch missing defaults.
 */
final class BackendCapabilitiesDefaultsTraitTest extends TestCase
{
    public function test_trait_provides_defaults_to_satisfy_interface(): void
    {
        $hostCustom = new class implements BackendCapabilities {
            use BackendCapabilitiesDefaults;

            public function key(): string { return 'host_custom'; }
            public function toolNameMap(): array { return []; }
            public function supportsSubAgents(): bool { return false; }
            public function supportsMcp(): bool { return false; }
            public function streamFormat(): string { return 'text'; }
            public function mcpConfigPath(): ?string { return null; }
            public function transformPrompt(string $prompt): string { return $prompt; }
            public function renderMcpConfig(array $servers): string { return ''; }
        };

        $this->assertInstanceOf(BackendCapabilities::class, $hostCustom);

        // Trait-supplied defaults — no-op for spawn-plan participation.
        $this->assertSame('', $hostCustom->spawnPreamble('/tmp/x'));
        $this->assertSame('', $hostCustom->consolidationPrompt(new SpawnPlan([], 1), [], '/tmp/x'));
    }

    public function test_host_can_override_trait_defaults_per_method(): void
    {
        $hostCustom = new class implements BackendCapabilities {
            use BackendCapabilitiesDefaults;

            public function key(): string { return 'host_custom_2'; }
            public function toolNameMap(): array { return []; }
            public function supportsSubAgents(): bool { return false; }
            public function supportsMcp(): bool { return false; }
            public function streamFormat(): string { return 'text'; }
            public function mcpConfigPath(): ?string { return null; }
            public function transformPrompt(string $prompt): string { return $prompt; }
            public function renderMcpConfig(array $servers): string { return ''; }

            // Override only one — leave consolidationPrompt at trait default.
            public function spawnPreamble(string $outputDir): string
            {
                return "Custom preamble for {$outputDir}";
            }
        };

        $this->assertSame('Custom preamble for /tmp/x', $hostCustom->spawnPreamble('/tmp/x'));
        $this->assertSame('', $hostCustom->consolidationPrompt(new SpawnPlan([], 1), [], '/tmp/x'));
    }
}
