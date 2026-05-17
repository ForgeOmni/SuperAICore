<?php

declare(strict_types=1);

namespace SuperAICore\Modes;

use Psr\Log\LoggerInterface;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAICore\Services\Dispatcher;

/**
 * The single seam every cross-layer mode (`CliAutoMode`,
 * `CliSmartOrchestrator`, `CliSquadOrchestrator`) routes its
 * per-step dispatches through. By routing all three modes through one
 * dispatcher we get two things for free:
 *
 *   1. **Double-layer cooperation.** A CLI-layer plan can place an
 *      `sdk:` provider on a step → that step runs through SuperAgent
 *      SDK directly (host's full provider stack with `extra_body`,
 *      `features`, `loop_detection`, etc.). And vice-versa: an SDK
 *      Squad plan can route a step at `cli:claude_cli` → goes back
 *      out through the CLI backend with its own MCP / hooks.
 *
 *   2. **Cross-layer recursion.** The provider tag `auto` / `smart` /
 *      `squad` is itself a valid target — when the dispatcher sees
 *      one of those it re-invokes the named mode on the step's
 *      prompt. So a squad can have an `auto` step whose CLI fan-out
 *      then has a `smart` sub-step whose subtasks land on individual
 *      CLI backends. Each layer keeps its own checkpointing /
 *      cost-tracking / mailbox.
 *
 * Provider tag grammar (case-insensitive):
 *
 *   cli:<name>     → SuperAICore CLI backend by name (claude_cli,
 *                    codex_cli, gemini_cli, kimi_cli, copilot_cli,
 *                    kiro_cli, anthropic_api, openai_api, …)
 *   sdk:<provider> → SuperAgent SDK provider, dispatched via the
 *                    `superagent` backend with `provider_config`
 *   auto           → recurse into CliAutoMode on the step's prompt
 *   smart          → recurse into CliSmartOrchestrator
 *   squad          → recurse into CliSquadOrchestrator
 *   (no prefix)    → treated as `sdk:<value>` for backward compat
 *
 * Tuple return: `{output: string, cost_usd: float, backend?: string,
 * model?: string, usage?: array}` — same shape SDK's PeerOrchestrator
 * already expects.
 */
class CrossLayerDispatcher
{
    /**
     * Bound modes for cross-layer recursion. Resolved lazily via the
     * container so we don't get circular ctor deps (CliAutoMode
     * constructs a CrossLayerDispatcher, which can re-enter
     * CliAutoMode).
     */
    private ?CliAutoMode $auto = null;
    private ?CliSmartOrchestrator $smart = null;
    private ?CliSquadOrchestrator $squad = null;

    public function __construct(
        private Dispatcher $coreDispatcher,
        private ?LoggerInterface $logger = null,
    ) {}

    public function setModes(
        CliAutoMode $auto,
        CliSmartOrchestrator $smart,
        CliSquadOrchestrator $squad,
    ): void {
        $this->auto = $auto;
        $this->smart = $smart;
        $this->squad = $squad;
    }

    /**
     * Dispatch one step. Used standalone by `CliAutoMode` and
     * `CliSmartOrchestrator`, and wrapped into a closure for SDK
     * `PeerOrchestrator` injection by `CliSquadOrchestrator`.
     *
     * @param  array{
     *   provider: string,
     *   model?: string,
     *   prompt: string,
     *   system?: ?string,
     *   max_tokens?: int,
     *   session_id?: ?string,
     *   metadata?: array<string,mixed>,
     *   options?: array<string,mixed>,
     * } $step
     * @return array{output: string, cost_usd: float, backend?: string, model?: string, usage?: array}
     */
    public function dispatch(array $step): array
    {
        $provider = strtolower((string) ($step['provider'] ?? ''));
        if ($provider === '') {
            return ['output' => '', 'cost_usd' => 0.0];
        }

        // Cross-layer recursion. Each named mode runs its own
        // dispatching loop; whatever final text it produces becomes
        // this step's output. Cost ledgers nest naturally.
        if ($provider === 'auto' && $this->auto !== null) {
            $r = $this->auto->run((string) $step['prompt'], $step['options'] ?? []);
            return ['output' => $r['text'] ?? '', 'cost_usd' => (float) ($r['cost_usd'] ?? 0.0)];
        }
        if ($provider === 'smart' && $this->smart !== null) {
            $r = $this->smart->run((string) $step['prompt'], $step['options'] ?? []);
            return ['output' => $r['text'] ?? '', 'cost_usd' => (float) ($r['cost_usd'] ?? 0.0)];
        }
        if ($provider === 'squad' && $this->squad !== null) {
            $r = $this->squad->run((string) $step['prompt'], $step['options'] ?? []);
            return ['output' => $r['text'] ?? '', 'cost_usd' => (float) ($r['cost_usd'] ?? 0.0)];
        }

        // Leaf dispatch — `cli:<name>` or `sdk:<provider>`.
        $kind = 'sdk';
        $target = $provider;
        if (str_contains($provider, ':')) {
            [$kind, $target] = explode(':', $provider, 2);
        }

        $dispatchOpts = [
            'prompt'     => (string) $step['prompt'],
            'system'     => $step['system'] ?? null,
            'max_tokens' => (int) ($step['max_tokens'] ?? 4096),
            'model'      => $step['model'] ?? null,
            'metadata'   => $step['metadata'] ?? [],
        ];
        if (!empty($step['session_id'])) {
            $dispatchOpts['metadata']['session_id'] = $step['session_id'];
        }
        // Forward whatever per-call SDK knobs the caller wanted
        // (reasoning_effort, features.*, handoff, idempotency_key…).
        foreach (['reasoning_effort', 'features', 'extra_body', 'loop_detection',
                  'handoff', 'idempotency_key', 'traceparent', 'tracestate'] as $passthru) {
            if (isset($step['options'][$passthru])) {
                $dispatchOpts[$passthru] = $step['options'][$passthru];
            }
        }

        if ($kind === 'cli') {
            $dispatchOpts['backend'] = $target;
        } else {
            // SDK route: pin the SuperAgent backend, let it pick the
            // SDK provider via provider_config.provider.
            $dispatchOpts['backend'] = 'superagent';
            $dispatchOpts['provider_config'] = array_merge(
                (array) ($step['options']['provider_config'] ?? []),
                ['provider' => $target],
            );
        }

        $result = $this->coreDispatcher->dispatch($dispatchOpts);
        if (!is_array($result)) {
            if ($this->logger) {
                $this->logger->warning('CrossLayerDispatcher: dispatch returned null', [
                    'kind' => $kind, 'target' => $target,
                ]);
            }
            return ['output' => '', 'cost_usd' => 0.0, 'backend' => $dispatchOpts['backend']];
        }

        return [
            'output'   => (string) ($result['text'] ?? ''),
            'cost_usd' => (float)  ($result['cost_usd'] ?? 0.0),
            'backend'  => (string) ($result['backend'] ?? $dispatchOpts['backend']),
            'model'    => (string) ($result['model'] ?? ''),
            'usage'    => (array)  ($result['usage'] ?? []),
        ];
    }

    /**
     * Wrap `dispatch()` into a `callable(SquadDispatchRequest)` closure
     * that SDK 1.0.0's `PeerOrchestrator` accepts directly. The role's
     * `provider` field carries the same grammar described in this
     * class's docblock.
     */
    public function squadAdapter(): callable
    {
        return function (SquadDispatchRequest $req): array {
            return $this->dispatch([
                'provider'   => $req->provider,
                'model'      => $req->model,
                'prompt'     => $req->prompt,
                'system'     => $req->systemPrompt,
                'session_id' => $req->sessionId,
                'metadata'   => [
                    'squad_role'      => $req->role->name,
                    'squad_role_tier' => $req->role->tier->value,
                ],
            ]);
        };
    }
}
