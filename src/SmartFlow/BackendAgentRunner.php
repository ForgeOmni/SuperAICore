<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAICore\Contracts\Backend;
use SuperAICore\Services\BackendRegistry;

/**
 * Executes a single {@see AgentCall} against whichever CLI/API backend the call
 * (or its persona) resolves to — this is the "跨 CLI agent" core: the same call
 * shape runs on `claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`,
 * `superagent`, `anthropic_api`, … via the shared {@see Backend} contract.
 *
 * Pipeline for one call:
 *   1. Resolve persona → backend/model/system/temperature defaults.
 *   2. In rehearsal mode, synthesize a deterministic schema-conforming stub at
 *      zero cost — no CLI is invoked, so flows run end-to-end without any CLI
 *      installed.
 *   3. Bake the JSON Schema into the prompt (CLI backends return free text, not
 *      a native `response_format`).
 *   4. Call {@see Backend::generate()} and read back text + usage + cost.
 *   5. Run the {@see StructuredOutputLadder} (submitted → extracted).
 *   6. Return an {@see AgentResult}.
 *
 * The runner never touches the budget or ledger — its caller ({@see Flow}) owns
 * those, so the same runner works in-process or inside a parallel worker.
 */
final class BackendAgentRunner
{
    private LoggerInterface $logger;
    private ?BackendRegistry $registry = null;
    private ?SuperAgentFlowBridge $bridge;

    /**
     * @param (callable(string): ?Backend)|null $backendResolver Test/DI seam: maps a backend
     *        name to a Backend instance. When null, a {@see BackendRegistry} is resolved lazily
     *        (from the container if booted, else freshly constructed).
     */
    public function __construct(
        private PersonaRegistry $personas,
        private bool $fake = false,
        private ?string $defaultBackend = null,
        private ?string $defaultModel = null,
        ?LoggerInterface $logger = null,
        private $backendResolver = null,
        ?SuperAgentFlowBridge $bridge = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->bridge = $bridge;
        if ($this->defaultBackend === null || $this->defaultBackend === '') {
            $cfg = Cfg::get('super-ai-core.smartflow.default_backend')
                ?? Cfg::get('super-ai-core.default_backend');
            $this->defaultBackend = is_string($cfg) && $cfg !== '' ? $cfg : 'claude_cli';
        }
    }

    public function isFake(): bool
    {
        return $this->fake;
    }

    public function run(AgentCall $call): AgentResult
    {
        // A delegation hands a sub-flow to the SuperAgent SDK's cross-model
        // SmartFlow instead of calling a single CLI backend.
        if ($call->delegation !== null) {
            return $this->delegate($call);
        }

        $persona = $call->role !== null ? ($this->personas->get($call->role) ?? []) : [];

        $backendName = (string) ($call->backend
            ?? ($persona['backend'] ?? $persona['provider'] ?? $this->defaultBackend));

        $model = $call->model
            ?? ($persona['model'] ?? null)
            ?? $this->defaultModel;

        $system = $this->composeSystem($persona, $call);
        $temperature = $call->temperature ?? ($persona['temperature'] ?? null);

        if ($this->fake) {
            return $this->rehearse($call, $backendName, (string) ($model ?? 'rehearsal'));
        }

        $backend = $this->resolveBackend($backendName);
        if (!$backend instanceof Backend) {
            return $this->failure($call, $backendName, (string) ($model ?? ''), "backend '{$backendName}' not registered");
        }

        // CLI backends return free text — bake the schema into the prompt rather
        // than rely on a native structured-output mode.
        $userPrompt = $call->schema !== null ? $this->schemaPrompt($call->prompt, $call->schema) : $call->prompt;

        $options = [
            'prompt' => $userPrompt,
            'max_tokens' => $call->maxTokens,
            'provider_config' => $this->providerConfig($persona, $call),
        ];
        if ($system !== null && $system !== '') {
            $options['system'] = $system;
        }
        if ($model !== null && $model !== '') {
            $options['model'] = (string) $model;
        }
        if ($temperature !== null) {
            $options['temperature'] = $temperature;
        }

        try {
            $envelope = $backend->generate($options);
        } catch (\Throwable $e) {
            return $this->failure($call, $backendName, (string) ($model ?? ''), 'generate failed: ' . $e->getMessage());
        }

        if (!is_array($envelope)) {
            return $this->failure($call, $backendName, (string) ($model ?? ''), 'backend returned no result');
        }

        $text = (string) ($envelope['text'] ?? '');
        $resolvedModel = (string) ($envelope['model'] ?? ($model ?? ''));
        [$inTok, $outTok] = $this->usageTokens($envelope['usage'] ?? []);
        $costUsd = (float) ($envelope['cost_usd'] ?? 0.0);

        $ladder = StructuredOutputLadder::resolve($text, $call->schema, false);

        if ($call->schema !== null && !$ladder['valid']) {
            $this->logger->warning('SmartFlow agent failed schema validation', [
                'label' => $call->label,
                'backend' => $backendName,
                'errors' => $ladder['errors'],
            ]);
        }

        return new AgentResult(
            value: $ladder['value'],
            text: $text,
            layer: $ladder['layer'],
            backend: $backendName,
            model: $resolvedModel,
            inputTokens: $inTok,
            outputTokens: $outTok,
            costUsd: $costUsd,
            valid: $ladder['valid'],
            error: $ladder['valid'] ? null : implode('; ', $ladder['errors']),
            fake: false,
        );
    }

    /**
     * Deterministic zero-cost rehearsal: schema calls get a stub that passes
     * validation; bare calls get a labelled placeholder string.
     */
    private function rehearse(AgentCall $call, string $backendName, string $model): AgentResult
    {
        if ($call->schema !== null) {
            $value = SchemaStub::generate($call->schema, $call->label);
            return new AgentResult(
                value: $value,
                text: json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                layer: 'native',
                backend: $backendName !== '' ? $backendName : 'rehearsal',
                model: $model,
                valid: true,
                fake: true,
            );
        }

        $text = "[rehearsal:{$backendName}] {$call->label}";
        return new AgentResult(
            value: $text,
            text: $text,
            layer: 'text',
            backend: $backendName !== '' ? $backendName : 'rehearsal',
            model: $model,
            valid: true,
            fake: true,
        );
    }

    /**
     * Hand a sub-flow to the SuperAgent SDK SmartFlow via the bridge. In
     * rehearsal mode the delegated flow rehearses too, so a nested run stays
     * zero-cost end-to-end. The sub-flow's cost/tokens propagate back so the
     * parent flow's budget federates over delegated spend.
     */
    private function delegate(AgentCall $call): AgentResult
    {
        $delegation = $call->delegation;
        $bridge = $this->bridge ??= new SuperAgentFlowBridge($this->logger);
        $res = $bridge->run($delegation, $this->fake);

        $backend = 'superagent';
        $model = (string) ($res['model'] ?? 'smartflow');

        if (!($res['ok'] ?? false)) {
            return new AgentResult(
                value: $call->schema !== null ? Skip::instance() : '',
                text: '',
                layer: 'none',
                backend: $backend,
                model: $model,
                valid: false,
                error: (string) ($res['error'] ?? 'delegation failed'),
                fake: $this->fake,
            );
        }

        $value = $res['value'];
        $valid = true;
        $errors = [];
        // If the delegating call requested a schema, validate the sub-flow's
        // returned value against it just like a normal agent call.
        if ($call->schema !== null) {
            $errors = SchemaValidator::validate($value, $call->schema);
            if ($errors !== []) {
                $valid = false;
                $value = Skip::instance();
            }
        }

        return new AgentResult(
            value: $value,
            text: is_string($res['value'])
                ? $res['value']
                : (json_encode($res['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            layer: 'delegated',
            backend: $backend,
            model: $model,
            inputTokens: (int) ($res['input_tokens'] ?? 0),
            outputTokens: (int) ($res['output_tokens'] ?? 0),
            costUsd: (float) ($res['cost_usd'] ?? 0.0),
            valid: $valid,
            error: $valid ? null : implode('; ', $errors),
            fake: $this->fake,
        );
    }

    private function composeSystem(array $persona, AgentCall $call): ?string
    {
        $parts = [];
        if (!empty($persona['system'])) {
            $parts[] = (string) $persona['system'];
        }
        if ($call->system !== null && $call->system !== '') {
            $parts[] = $call->system;
        }
        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * @param array<string, mixed> $persona
     * @return array<string, mixed>
     */
    private function providerConfig(array $persona, AgentCall $call): array
    {
        $base = is_array($persona['provider_config'] ?? null) ? $persona['provider_config'] : [];
        return array_merge($base, $call->providerConfig);
    }

    /**
     * Read input/output token counts out of a backend's `usage` block, tolerating
     * the several shapes backends emit (input_tokens/output_tokens, prompt/completion).
     *
     * @return array{0:int,1:int}
     */
    private function usageTokens(mixed $usage): array
    {
        if (!is_array($usage)) {
            return [0, 0];
        }
        $in = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? $usage['input'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? $usage['output'] ?? 0);
        return [$in, $out];
    }

    private function schemaPrompt(string $prompt, array $schema): string
    {
        $schemaStr = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
{$prompt}

Respond with ONLY a JSON value conforming to this JSON Schema — no prose, no code fence:
{$schemaStr}
PROMPT;
    }

    private function resolveBackend(string $name): ?Backend
    {
        if ($this->backendResolver !== null) {
            return ($this->backendResolver)($name);
        }
        return $this->backendRegistry()?->get($name);
    }

    private function backendRegistry(): ?BackendRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }
        if (function_exists('app')) {
            try {
                return $this->registry = app(BackendRegistry::class);
            } catch (\Throwable) {
                // fall through to a fresh registry
            }
        }
        try {
            return $this->registry = new BackendRegistry($this->logger);
        } catch (\Throwable $e) {
            $this->logger->error('SmartFlow could not build a BackendRegistry', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function failure(AgentCall $call, string $backend, string $model, string $error): AgentResult
    {
        $this->logger->error('SmartFlow agent run failed', ['label' => $call->label, 'error' => $error]);

        return new AgentResult(
            value: $call->schema !== null ? Skip::instance() : '',
            text: '',
            layer: 'none',
            backend: $backend,
            model: $model,
            valid: false,
            error: $error,
            fake: $this->fake,
        );
    }
}
