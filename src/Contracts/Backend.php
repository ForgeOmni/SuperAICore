<?php

namespace SuperAICore\Contracts;

/**
 * Unified contract all execution backends implement.
 *
 * A backend takes a prompt (+ optional system, messages, max_tokens) plus
 * a provider-config array (credentials, model, base_url) and returns either
 * plain text or null on failure.
 */
interface Backend
{
    /**
     * @return string machine identifier (claude_cli | codex_cli | gemini_cli | superagent | anthropic_api | openai_api | gemini_api)
     */
    public function name(): string;

    /**
     * @param  array $options
     *   Required keys:
     *     - prompt: string                (OR messages: array<{role, content}>)
     *   Optional keys:
     *     - system: string                System prompt
     *     - messages: array               Pre-built message array (overrides prompt)
     *     - max_tokens: int               Default 500
     *     - model: string                 Backend default used if absent
     *     - provider_config: array        Credentials + endpoint config
     *         (api_key, base_url, region, project_id, etc.)
     * @return array|null
     *   ['text' => string, 'model' => string, 'usage' => [input_tokens, output_tokens], 'cost_usd' => float]
     *   or null on failure
     */
    public function generate(array $options): ?array;

    /**
     * Whether this backend can run given current environment / config.
     * E.g. Claude CLI checks the binary exists in PATH.
     */
    public function isAvailable(array $providerConfig = []): bool;
}
