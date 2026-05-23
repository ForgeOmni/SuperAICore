<?php

namespace SuperAICore\Tests\Fixtures;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Messages\AssistantMessage;

/**
 * Test double for the SuperAgent SDK's LLMProvider contract.
 *
 * Lives in tests/Fixtures/ rather than inline at the bottom of
 * SuperAgentBackendTest.php so the host file parses cleanly when the
 * forgeomni/superagent SDK is absent (the "phpunit-no-superagent" CI job
 * removes the SDK before running the suite). Inline `implements
 * LLMProvider` triggered autoload of the missing interface at file-load
 * time, fatal-erroring PHPUnit before any test's setUp() could
 * markTestSkipped().
 *
 * Required only when the test class actually exercises a provider, which
 * cannot happen when the skip fires. PSR-4 autoload keeps it untouched
 * in the SDK-missing job.
 */
final class TestSuperAgentProvider implements LLMProvider
{
    public static ?AssistantMessage $nextResponse = null;
    public static ?string $lastRegion = null;
    public static ?\Throwable $throw = null;

    public static function reset(): void
    {
        self::$nextResponse = null;
        self::$lastRegion   = null;
        self::$throw        = null;
    }

    public function __construct(array $config = [])
    {
        self::$lastRegion = $config['region'] ?? null;
    }

    public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): \Generator
    {
        if (self::$throw !== null) {
            $t = self::$throw;
            self::$throw = null;
            throw $t;
        }
        yield self::$nextResponse ?? new AssistantMessage();
    }

    public function formatMessages(array $messages): array { return $messages; }
    public function formatTools(array $tools): array { return []; }
    public function getModel(): string { return 'sa-test-model'; }
    public function setModel(string $model): void {}
    public function name(): string { return 'sa-test'; }
}
