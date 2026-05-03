<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Memory\Embeddings\EmbeddingProvider;
use SuperAICore\Registry\Skill;

/**
 * Optional semantic reranker that runs over `SkillRanker`'s BM25 top-N.
 * Borrowed from jcode, which embeds the user prompt + every skill blurb
 * with a bundled MiniLM-L6-v2 ONNX model and reorders by cosine similarity.
 *
 * Embedder backend is resolved through `EmbeddingProviderFactory`, which
 * reads `super-ai-core.embeddings.{provider,callback,ollama_url,…}` and
 * delegates to SuperAgent SDK 0.9.7's `Memory\Embeddings` package
 * (`OllamaEmbeddingProvider`, `CallableEmbeddingProvider`, or any
 * host-supplied `EmbeddingProvider`). When no embedder is configured the
 * reranker is a clean no-op and the caller gets BM25 ordering back
 * unchanged — same contract as before, but the HTTP / shape-detection
 * code now lives in the SDK so the SDK's own `SemanticSkillRouter` and
 * this class share a single embedder instance + cache when both are
 * resolved through the container.
 */
class SemanticSkillReranker
{
    /** Cosine similarities below this are dropped before reranking. */
    private const MIN_COSINE = 0.05;

    /** Final = bm25_score * (1 + alpha * (cosine - 0.5)). */
    private const ALPHA = 0.6;

    private static bool $warnedNoEmbedder = false;

    /**
     * Per-process query-vector cache so repeated calls with the same query
     * (typical in batch ranking / test harnesses) don't re-embed. Keyed by
     * `provider->fingerprint() . sha1(query)` so a model swap mid-run
     * invalidates without polluting unrelated entries.
     *
     * @var array<string, list<float>>
     */
    private static array $queryCache = [];

    public function __construct(
        private readonly ?EmbeddingProviderFactory $factory = null,
    ) {}

    /**
     * Rerank an existing list of `[skill, score, breakdown]` tuples
     * (the shape `SkillRanker::rank()` returns). Pass the original query
     * so we can embed it on this call.
     *
     * @param  string $query
     * @param  array<int, array{skill:Skill, score:float, breakdown:array}> $bm25Hits
     * @return array<int, array{skill:Skill, score:float, breakdown:array}>
     */
    public function rerank(string $query, array $bm25Hits): array
    {
        if ($bm25Hits === [] || $query === '') return $bm25Hits;

        $provider = $this->resolveProvider();
        if ($provider === null) {
            if (!self::$warnedNoEmbedder && function_exists('error_log')) {
                self::$warnedNoEmbedder = true;
                error_log('[SuperAICore] SemanticSkillReranker: no embedder configured, returning BM25 ordering. Configure super-ai-core.embeddings.{provider|callback|ollama_url} to enable.');
            }
            return $bm25Hits;
        }

        $corpus = [];
        foreach ($bm25Hits as $hit) {
            $corpus[] = $this->skillBlurb($hit['skill']);
        }

        try {
            $queryVec = $this->embedQuery($provider, $query);
            if ($queryVec === []) return $bm25Hits;
            $vectors = $provider->embed($corpus);
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[SuperAICore] SemanticSkillReranker embed failed: ' . $e->getMessage());
            }
            return $bm25Hits;
        }
        if (count($vectors) !== count($corpus)) {
            return $bm25Hits;
        }

        $sourceLabel = $provider->fingerprint();
        $reranked = [];
        foreach ($bm25Hits as $i => $hit) {
            $skillVec = $vectors[$i] ?? [];
            if (!is_array($skillVec) || $skillVec === []) {
                // Per-row failure (Ollama flake): keep BM25 score, no boost.
                $reranked[] = $hit;
                continue;
            }
            $cosine = $this->cosine($queryVec, array_values(array_map('floatval', $skillVec)));
            if ($cosine < self::MIN_COSINE) {
                $finalScore = $hit['score'];
            } else {
                $finalScore = $hit['score'] * (1.0 + self::ALPHA * ($cosine - 0.5));
            }
            $hit['score'] = round($finalScore, 4);
            $hit['breakdown'] = $hit['breakdown'] + [
                'cosine'        => round($cosine, 4),
                'rerank_alpha'  => self::ALPHA,
                'rerank_source' => $sourceLabel,
            ];
            $reranked[] = $hit;
        }
        usort($reranked, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return $reranked;
    }

    private function resolveProvider(): ?EmbeddingProvider
    {
        $factory = $this->factory;
        if ($factory === null && function_exists('app')) {
            try {
                $factory = app(EmbeddingProviderFactory::class);
            } catch (\Throwable) {
                return null;
            }
        }
        return $factory?->make();
    }

    /**
     * Embed the query once per fingerprint+query pair. Returns [] on
     * provider failure so the caller can early-out without a boost.
     *
     * @return list<float>
     */
    private function embedQuery(EmbeddingProvider $provider, string $query): array
    {
        $key = $provider->fingerprint() . ':' . sha1($query);
        if (isset(self::$queryCache[$key])) return self::$queryCache[$key];

        $vectors = $provider->embed([$query]);
        $vec = is_array($vectors[0] ?? null) ? array_values(array_map('floatval', $vectors[0])) : [];
        return self::$queryCache[$key] = $vec;
    }

    private function skillBlurb(Skill $s): string
    {
        $parts = [$s->name];
        if ($s->description) $parts[] = $s->description;
        $parts[] = mb_substr($s->body ?? '', 0, 800);
        return trim(implode("\n", $parts));
    }

    /** @param list<float> $a @param list<float> $b */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) return 0.0;
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * Reset the static caches — exposed for tests that flip the config
     * mid-run. Production code should never call this.
     */
    public static function resetForTests(): void
    {
        self::$warnedNoEmbedder = false;
        self::$queryCache = [];
    }
}
