<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Memory\Embeddings\CallableEmbeddingProvider;
use SuperAgent\Memory\Embeddings\EmbeddingProvider;
use SuperAgent\Memory\Embeddings\OllamaEmbeddingProvider;

/**
 * Build the bundled `EmbeddingProvider` from `super-ai-core.embeddings.*`
 * config. Returns null when no embedder is configured (or SuperAgent SDK
 * 0.9.7's Memory\Embeddings package is unavailable) so callers can degrade
 * to BM25 / keyword fallbacks without explicit feature gates.
 *
 * Resolution order (first match wins):
 *
 *   1. `super-ai-core.embeddings.provider`  — already-instantiated
 *      `EmbeddingProvider` (host wired its own subclass / OnnxEmbeddingProvider
 *      / OpenAI-backed adapter via DI).
 *   2. `super-ai-core.embeddings.callback`  — closure of either shape:
 *      `fn(list<string>): list<list<float>>` or `fn(string): list<float>`.
 *      `CallableEmbeddingProvider` auto-detects the parameter type.
 *   3. `super-ai-core.embeddings.ollama_url` — local Ollama daemon
 *      (`/api/embeddings`, default model `nomic-embed-text`).
 *   4. None — return null.
 *
 * Why a factory and not a `singleton(EmbeddingProvider::class, …)` binding:
 * the typed binding can't return null cleanly, but our hot paths
 * (SemanticSkillReranker, SuperAgentBackend) need to ask "is there one?"
 * without throwing. A factory with `make(): ?EmbeddingProvider` keeps the
 * type honest and lets every consumer share the same instance via the
 * container singleton.
 */
final class EmbeddingProviderFactory
{
    /** Cached so repeated calls in the same request reuse one instance. */
    private ?EmbeddingProvider $cached = null;
    private bool $resolved = false;

    public function make(): ?EmbeddingProvider
    {
        if ($this->resolved) return $this->cached;
        $this->resolved = true;

        if (!interface_exists(EmbeddingProvider::class)) {
            return $this->cached = null;
        }
        if (!function_exists('config')) {
            return $this->cached = null;
        }

        $explicit = config('super-ai-core.embeddings.provider');
        if ($explicit instanceof EmbeddingProvider) {
            return $this->cached = $explicit;
        }

        $cb = config('super-ai-core.embeddings.callback');
        if (is_callable($cb)) {
            $fingerprint = (string) (config('super-ai-core.embeddings.fingerprint') ?? 'callback:host');
            return $this->cached = new CallableEmbeddingProvider($cb, fingerprint: $fingerprint);
        }

        $url = (string) (config('super-ai-core.embeddings.ollama_url') ?? '');
        if ($url !== '') {
            $model = (string) (config('super-ai-core.embeddings.ollama_model') ?? 'nomic-embed-text');
            $timeoutMs = (int) (config('super-ai-core.embeddings.timeout_ms') ?? 10_000);
            return $this->cached = new OllamaEmbeddingProvider($url, $model, $timeoutMs);
        }

        return $this->cached = null;
    }

    /** Drop the cached instance — exposed for tests that flip config mid-run. */
    public function reset(): void
    {
        $this->cached = null;
        $this->resolved = false;
    }
}
