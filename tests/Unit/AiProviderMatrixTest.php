<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Models\AiProvider;
use SuperAICore\Tests\TestCase;

class AiProviderMatrixTest extends TestCase
{
    public function test_claude_backend_allows_anthropic_family_plus_builtin(): void
    {
        $types = AiProvider::typesForBackend(AiProvider::BACKEND_CLAUDE);
        $this->assertContains(AiProvider::TYPE_BUILTIN, $types);
        $this->assertContains(AiProvider::TYPE_ANTHROPIC, $types);
        $this->assertContains(AiProvider::TYPE_ANTHROPIC_PROXY, $types);
        $this->assertContains(AiProvider::TYPE_BEDROCK, $types);
        $this->assertContains(AiProvider::TYPE_VERTEX, $types);
        $this->assertNotContains(AiProvider::TYPE_OPENAI, $types);
        $this->assertNotContains(AiProvider::TYPE_OPENAI_COMPATIBLE, $types);
    }

    public function test_codex_backend_allows_openai_family_plus_builtin(): void
    {
        $types = AiProvider::typesForBackend(AiProvider::BACKEND_CODEX);
        $this->assertContains(AiProvider::TYPE_BUILTIN, $types);
        $this->assertContains(AiProvider::TYPE_OPENAI, $types);
        $this->assertContains(AiProvider::TYPE_OPENAI_COMPATIBLE, $types);
        $this->assertNotContains(AiProvider::TYPE_ANTHROPIC, $types);
        $this->assertNotContains(AiProvider::TYPE_BEDROCK, $types);
    }

    public function test_superagent_backend_allows_both_provider_families_without_builtin(): void
    {
        $types = AiProvider::typesForBackend(AiProvider::BACKEND_SUPERAGENT);
        $this->assertContains(AiProvider::TYPE_ANTHROPIC, $types);
        $this->assertContains(AiProvider::TYPE_ANTHROPIC_PROXY, $types);
        $this->assertContains(AiProvider::TYPE_OPENAI, $types);
        $this->assertContains(AiProvider::TYPE_OPENAI_COMPATIBLE, $types);
        $this->assertNotContains(AiProvider::TYPE_BUILTIN, $types);
        $this->assertNotContains(AiProvider::TYPE_BEDROCK, $types);
    }

    public function test_unknown_backend_returns_empty_array(): void
    {
        $this->assertSame([], AiProvider::typesForBackend('nope'));
    }
}
