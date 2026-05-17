<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\Strategies\CacheAwareCompressor;
use SuperAgent\Context\Strategies\CompressionStrategy;
use SuperAgent\Context\Strategies\ConversationCompressor;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\LLM\ProviderInterface;

/**
 * Builds the project-wide default `CompressionStrategy` for hosts that
 * drive their own `ContextManager` flow (long-running chat sessions
 * persisted across processes). The factory wires SDK 0.9.8's
 * `CacheAwareCompressor` around the bundled `ConversationCompressor`
 * so the prompt-cache prefix never gets clobbered by compaction.
 *
 * Why this is needed even though `SuperAgentBackend` is mostly
 * single-turn:
 *
 *   - Once a host pushes max_turns > 1 (sub-agent loops, browser-tool
 *     sessions, multi-step refactors) the SDK's `Agent` will summarise
 *     the head of the history when token budget runs out. By default
 *     the summary lands AT byte 0 of the next request and the cached
 *     prefix is gone, eating the 10× cost discount.
 *   - The cache-aware wrapper pins 1 system + 4 conversation messages
 *     at the head, so a `summary_boundary` lands AFTER the cache prefix
 *     and the discount survives.
 *
 * Toggle via `super-ai-core.compression.cache_aware` (default true).
 * Pin sizes are configurable for hosts that have longer onboarding
 * preambles or shorter heads.
 *
 * Hosts integrate by resolving `CompressionStrategy::class` from the
 * container when constructing their own `ContextManager`:
 *
 *   $strategy = app(CompressionStrategy::class)
 *       ->build($tokenEstimator, $compressionConfig, $provider);
 *   $contextManager->addStrategy($strategy);
 */
final class CompressionStrategyFactory
{
    public function __construct(
        private bool $cacheAware = true,
        private int $pinHead = 4,
        private bool $pinSystem = true,
    ) {}

    /**
     * Build a fresh strategy. We don't memoize because each
     * `ContextManager` instance owns its own token estimator + provider
     * pair, so a singleton wouldn't be reusable across sessions.
     */
    public function build(
        TokenEstimator $tokenEstimator,
        CompressionConfig $config,
        ProviderInterface $provider,
    ): CompressionStrategy {
        $inner = new ConversationCompressor($tokenEstimator, $config, $provider);
        if (!$this->cacheAware) return $inner;
        if (!class_exists(CacheAwareCompressor::class)) return $inner;
        return new CacheAwareCompressor(
            delegate:       $inner,
            tokenEstimator: $tokenEstimator,
            config:         $config,
            pinHead:        $this->pinHead,
            pinSystem:      $this->pinSystem,
        );
    }

    public function isCacheAware(): bool
    {
        return $this->cacheAware && class_exists(CacheAwareCompressor::class);
    }
}
