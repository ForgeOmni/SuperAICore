<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Providers\DeepSeekProvider;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Standalone wrapper around SDK 0.9.8 `DeepSeekProvider::completeFim()`
 * — DeepSeek's FIM (Fill-In-the-Middle) endpoint on the `beta` region.
 *
 * The Backend abstraction is chat-shaped (prompt → text), so FIM
 * doesn't fit cleanly. Hosts that want prefix-completion / inline-fill
 * code use cases call this service directly:
 *
 *   $fim = app(DeepSeekFimService::class);
 *   $text = $fim->complete(
 *       prefix:  "function calculateTax(\$amount, \$rate) {\n",
 *       suffix:  "\n}\n",
 *       maxTokens: 64,
 *   );
 *
 * The service constructs a per-call `DeepSeekProvider(region: beta)`
 * because the chat-region provider explicitly refuses FIM calls. We
 * intentionally don't memoize: FIM dispatches are typically short-lived
 * (one IDE keystroke = one call) and the construction cost is minimal.
 */
final class DeepSeekFimService
{
    public function __construct(
        private ?string $apiKey = null,
    ) {}

    public function isAvailable(): bool
    {
        return class_exists(DeepSeekProvider::class) && $this->resolveApiKey() !== '';
    }

    /**
     * @param  array<string,mixed> $options  Forwarded to completeFim():
     *                                       model, max_tokens, temperature,
     *                                       top_p, stop.
     */
    public function complete(string $prefix, string $suffix = '', array $options = []): ?string
    {
        if (!$this->isAvailable()) return null;

        try {
            /** @var DeepSeekProvider $provider */
            $provider = ProviderRegistry::createForHost('deepseek', [
                'api_key' => $this->resolveApiKey(),
                'region'  => 'beta',
                'model'   => $options['model'] ?? 'deepseek-v4-flash',
                'extra'   => [],
            ]);
            return $provider->completeFim($prefix, $suffix, $options);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveApiKey(): string
    {
        if ($this->apiKey !== null && $this->apiKey !== '') return $this->apiKey;
        return (string) (getenv('DEEPSEEK_API_KEY') ?: '');
    }
}
