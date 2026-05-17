<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Evals\ScoreCatalog;
use SuperAgent\Messages\Message;
use SuperAgent\Routing\AutoModelStrategy;

/**
 * Host wrapper around SDK 0.9.8's `Routing\AutoModelStrategy` — the
 * `/model auto` heuristic that escalates Flash → Pro based on prompt
 * size, tool-chain depth, explicit `reasoning_effort=max`, and intent
 * keywords in the system prompt.
 *
 * Why this lives here and not directly in `SuperAgentBackend`:
 *
 *   - It needs to be reachable from every dispatch path, not just the
 *     `superagent` backend. The Claude / Codex / Gemini CLI backends
 *     also benefit from auto-routing once a `provider_config` carries
 *     the candidate models in `auto_models` (Pro + Flash entries).
 *   - Wrapping behind a service gives hosts a single seam to swap the
 *     SDK's `AutoModelStrategy` for their own (e.g. one that consults
 *     a proprietary eval catalog or a feature-flag system).
 *   - The SDK's `selectWithScores()` consumes a `ScoreCatalog`; when
 *     `super-ai-core.auto_model.score_catalog_path` is wired the host
 *     gets eval-driven routing for free. Without it the heuristic
 *     `select()` path is used.
 *
 * Config knobs (all optional):
 *
 *   - `super-ai-core.auto_model.enabled`              bool, default true
 *   - `super-ai-core.auto_model.long_context_tokens`  int,  default 32_000
 *   - `super-ai-core.auto_model.tool_chain_threshold` int,  default 3
 *   - `super-ai-core.auto_model.pro_keywords`         list<string>, defaults to SDK's
 *   - `super-ai-core.auto_model.score_catalog_path`   ?string, opt-in eval routing
 *   - `super-ai-core.auto_model.pro_model`            ?string, override `deepseek-v4-pro`
 *   - `super-ai-core.auto_model.flash_model`          ?string, override `deepseek-v4-flash`
 *
 * The strategy is stateless across calls — fast to construct, no need
 * to memoize within a request. We bind it as a Laravel singleton because
 * the `ScoreCatalog` *is* somewhat expensive to construct (reads JSON
 * off disk on first access) and we want one instance per request cycle.
 */
final class AutoModelRouter
{
    private ?AutoModelStrategy $strategy = null;

    public function __construct(
        private ?string $proModel = null,
        private ?string $flashModel = null,
        private int $longContextThresholdTokens = 32_000,
        private int $toolChainThreshold = 3,
        /** @var list<string>|null */
        private ?array $proKeywords = null,
        private ?ScoreCatalog $scoreCatalog = null,
    ) {}

    /**
     * Pick the model id for this dispatch. Pure pass-through to the SDK
     * heuristic — callers feed in the same `$messages` / `$systemPrompt`
     * / `$options` triplet the Agent would see, and get back a single
     * model id string.
     *
     * When the score catalog is wired and the prompt's intent keyword
     * maps to a known eval dim, the catalog's top-scoring model wins
     * over the Pro/Flash heuristic.
     *
     * @param  Message[]              $messages
     * @param  array<string, mixed>   $options
     */
    public function select(array $messages, ?string $systemPrompt = null, array $options = []): string
    {
        $strategy = $this->strategy();
        $pick = $strategy->selectWithScores($messages, $systemPrompt, $options);
        return $this->mapModel($pick);
    }

    /**
     * Surface the trailing tool-chain depth so callers can log "we
     * escalated because depth hit N" without re-walking the messages.
     *
     * @param Message[] $messages
     */
    public function trailingToolChainDepth(array $messages): int
    {
        return $this->strategy()->trailingToolChainDepth($messages);
    }

    private function strategy(): AutoModelStrategy
    {
        if ($this->strategy !== null) return $this->strategy;
        $args = [
            'longContextThresholdTokens' => $this->longContextThresholdTokens,
            'toolChainThreshold'         => $this->toolChainThreshold,
        ];
        if ($this->proKeywords !== null) {
            $args['proIntentKeywords'] = array_values($this->proKeywords);
        }
        if ($this->scoreCatalog !== null) {
            $args['scoreCatalog'] = $this->scoreCatalog;
        }
        $this->strategy = new AutoModelStrategy(...$args);
        return $this->strategy;
    }

    private function mapModel(string $sdkPick): string
    {
        // SDK currently emits the DeepSeek V4 ids; let hosts remap
        // them to other equivalent Pro/Flash pairs (e.g. claude-opus /
        // claude-haiku) without touching the SDK heuristic.
        return match ($sdkPick) {
            AutoModelStrategy::PRO   => $this->proModel   ?? $sdkPick,
            AutoModelStrategy::FLASH => $this->flashModel ?? $sdkPick,
            default                  => $sdkPick,
        };
    }
}
