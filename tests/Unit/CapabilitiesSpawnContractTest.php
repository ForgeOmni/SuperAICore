<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\AgentSpawn\SpawnPlan;
use SuperAICore\Capabilities\ClaudeCapabilities;
use SuperAICore\Capabilities\CodexCapabilities;
use SuperAICore\Capabilities\CopilotCapabilities;
use SuperAICore\Capabilities\GeminiCapabilities;
use SuperAICore\Capabilities\KiroCapabilities;
use SuperAICore\Capabilities\SuperAgentCapabilities;
use SuperAICore\Contracts\BackendCapabilities;

/**
 * Phase C contract: every BackendCapabilities implementation must expose
 * `spawnPreamble()` and `consolidationPrompt()`. The protocol-active
 * engines (codex, gemini) must return non-empty strings; the rest opt
 * out by returning ''.
 */
final class CapabilitiesSpawnContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<BackendCapabilities>, bool}>
     */
    public static function capabilityProvider(): iterable
    {
        // [class, expected to participate in spawn-plan protocol]
        yield 'claude'     => [ClaudeCapabilities::class,     false];
        yield 'codex'      => [CodexCapabilities::class,      true];
        yield 'gemini'     => [GeminiCapabilities::class,     true];
        yield 'kiro'       => [KiroCapabilities::class,       false];
        yield 'copilot'    => [CopilotCapabilities::class,    false];
        yield 'superagent' => [SuperAgentCapabilities::class, false];
    }

    /**
     * @dataProvider capabilityProvider
     */
    public function test_implements_phase_c_methods(string $class, bool $participates): void
    {
        $cap = new $class();
        $this->assertInstanceOf(BackendCapabilities::class, $cap);

        // Just ensure the methods exist + are callable without throwing
        $this->assertIsString($cap->spawnPreamble('/tmp/x'));
        $plan = new SpawnPlan([], 1);
        $this->assertIsString($cap->consolidationPrompt($plan, [], '/tmp/x'));
    }

    /**
     * @dataProvider capabilityProvider
     */
    public function test_protocol_participation_matches_expected(string $class, bool $participates): void
    {
        $cap = new $class();
        $preamble = $cap->spawnPreamble('/tmp/x');
        $consolidation = $cap->consolidationPrompt(new SpawnPlan([], 1), [], '/tmp/x');

        if ($participates) {
            $this->assertNotEmpty(
                $preamble,
                "{$class} should expose a non-empty spawn preamble (codex/gemini participate in the protocol)."
            );
            $this->assertNotEmpty(
                $consolidation,
                "{$class} should expose a non-empty consolidation prompt."
            );
        } else {
            $this->assertSame('', $preamble, "{$class} should opt out of the spawn-plan protocol with an empty preamble.");
            $this->assertSame('', $consolidation, "{$class} should opt out of the spawn-plan protocol with an empty consolidation prompt.");
        }
    }

    public function test_codex_consolidation_prompt_lists_each_agent(): void
    {
        $cap = new CodexCapabilities();
        $plan = new SpawnPlan([
            ['name' => 'cto-vogels', 'system_prompt' => 's', 'task_prompt' => 't', 'output_subdir' => 'cto-vogels'],
            ['name' => 'ceo-bezos',  'system_prompt' => 's', 'task_prompt' => 't', 'output_subdir' => 'ceo-bezos'],
        ], 4);
        $report = [
            ['name' => 'cto-vogels', 'exit' => 0, 'log' => '/x', 'duration_ms' => 100, 'error' => null],
            ['name' => 'ceo-bezos',  'exit' => 1, 'log' => '/y', 'duration_ms' => 200, 'error' => 'crashed'],
        ];

        $prompt = $cap->consolidationPrompt($plan, $report, '/tmp/run');

        $this->assertStringContainsString('cto-vogels', $prompt);
        $this->assertStringContainsString('ceo-bezos', $prompt);
        $this->assertStringContainsString('exit=0', $prompt);
        $this->assertStringContainsString('exit=1', $prompt);
        $this->assertStringContainsString('/tmp/run/cto-vogels/', $prompt);
        $this->assertStringContainsString('Do NOT write `_spawn_plan.json` again', $prompt);
    }
}
