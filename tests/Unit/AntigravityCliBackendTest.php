<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\AntigravityCliBackend;
use SuperAICore\Services\AntigravityModelResolver;

final class AntigravityCliBackendTest extends TestCase
{
    public function test_name_is_antigravity_cli(): void
    {
        $this->assertSame('antigravity_cli', (new AntigravityCliBackend())->name());
    }

    public function test_build_command_uses_print_skip_permissions_and_display_model(): void
    {
        $b = new ExposedAntigravityCliBackend();
        $cmd = $b->command('do the thing', 'Gemini 3.5 Flash (Low)', ['timeout' => 120]);

        $this->assertSame('agy', $cmd[0]);
        $this->assertContains('-p', $cmd);
        $this->assertContains('do the thing', $cmd);
        $this->assertContains('--dangerously-skip-permissions', $cmd);
        // Server-side print wait mirrors our timeout (Go duration syntax).
        $this->assertContains('--print-timeout', $cmd);
        $this->assertContains('120s', $cmd);
        $this->assertContains('--model', $cmd);
        $this->assertContains('Gemini 3.5 Flash (Low)', $cmd);
    }

    public function test_build_command_omits_model_when_null(): void
    {
        $b = new ExposedAntigravityCliBackend();
        $cmd = $b->command('hi', null, []);
        $this->assertNotContains('--model', $cmd);
    }

    public function test_build_command_resume_flags(): void
    {
        $b = new ExposedAntigravityCliBackend();

        $cmd = $b->command('hi', null, ['resume_session_id' => 'conv-42']);
        $this->assertContains('--conversation', $cmd);
        $this->assertContains('conv-42', $cmd);

        $cmd = $b->command('hi', null, ['continue_session' => true]);
        $this->assertContains('--continue', $cmd);

        // Explicit conversation id wins over --continue.
        $cmd = $b->command('hi', null, ['resume_session_id' => 'conv-9', 'continue_session' => true]);
        $this->assertContains('--conversation', $cmd);
        $this->assertNotContains('--continue', $cmd);
    }

    public function test_envelope_shape_is_subscription_zero_usage(): void
    {
        $b = new ExposedAntigravityCliBackend();
        $env = $b->envelope('the answer', 'Claude Opus 4.6 (Thinking)');

        $this->assertSame('the answer', $env['text']);
        $this->assertSame('Claude Opus 4.6 (Thinking)', $env['model']);
        $this->assertSame(0, $env['usage']['input_tokens']);
        $this->assertSame(0, $env['usage']['output_tokens']);
        $this->assertArrayNotHasKey('cost_usd', $env);
    }
}

/** Exposes protected seams for unit assertions. */
final class ExposedAntigravityCliBackend extends AntigravityCliBackend
{
    /** @param array<string,mixed> $options */
    public function command(string $prompt, ?string $model, array $options): array
    {
        return $this->buildCommand($prompt, $model, $options);
    }

    public function envelope(string $text, ?string $model): array
    {
        return $this->buildEnvelope($text, $model);
    }
}
