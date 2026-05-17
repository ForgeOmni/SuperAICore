<?php

declare(strict_types=1);

namespace SuperAICore\Backends;

use Psr\Log\LoggerInterface;
use SuperAgent\Agent;
use SuperAgent\Pipeline\StepStatus;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\ModelTierMap;
use SuperAgent\Squad\PeerOrchestrator;
use SuperAgent\Squad\SquadCheckpointStore;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAgent\Squad\SubTask;
use SuperAgent\Squad\TaskDecomposer;
use SuperAICore\Contracts\Backend;

/**
 * SDK 1.0.0 — Adaptive Cross-Model Squad backend.
 *
 * Drives a heuristic-decomposed pipeline with one model per subtask
 * (tier-mapped via `ModelTierMap`), per-step checkpointing, optional
 * cost cap with downshift, and peer-to-peer messaging via SDK's
 * `PeerMailbox`.
 *
 * Activation
 *   - `superagent` backend stays single-call; route the dispatch to
 *     `squad` when the operator picks "auto + multi-agent" in the UI,
 *     or when `task_complexity >= HARD` and `super-ai-core.squad.auto`
 *     is on (host wires that policy outside this class).
 *
 * Inputs (Backend::generate `$options`):
 *   - prompt:                string  (required) — free-form task
 *   - subtasks:              ?array  — pre-decomposed; if absent the
 *                                       heuristic `TaskDecomposer` runs
 *   - squad_id:              ?string — stable id; one is derived if absent
 *   - tier_map:              ?array  — band ⇒ {provider, model} overrides
 *   - max_cost_usd:          ?float  — pipeline budget; downshift at 80%
 *   - checkpoint_dir:        ?string — where SquadCheckpointStore writes
 *   - require_approval:      bool    — wrap as auto-approve callable
 *                                       (host can swap via setApprovalHandler)
 *
 * Envelope (success):
 *   - text:                merged outputs (last completed step's text,
 *                          or a delimited concat across all steps)
 *   - model:               'squad:<tier-map-fingerprint>'
 *   - usage:               zeroed (per-step USD comes back from the
 *                          dispatcher's `cost_usd` tuple — squad doesn't
 *                          re-derive tokens because that's per-step)
 *   - cost_usd:            sum across step dispatches
 *   - turns:               number of steps that ran
 *   - squad: {
 *       squad_id:         string
 *       step_count:       int
 *       completed:        list<string>
 *       roles:            list<{name, provider, model, tier}>
 *       checkpoint_path:  ?string
 *       mailbox_log:      list<…>  (peer-message audit trail)
 *     }
 *
 * Failure: returns null and logs at warning level. Mid-run failures
 * still leave the checkpoint on disk; the host can resume by passing
 * the same `squad_id` and `checkpoint_dir` on the next dispatch.
 */
class SquadBackend implements Backend
{
    public function __construct(
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'squad';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        return class_exists(PeerOrchestrator::class) && class_exists(Agent::class);
    }

    public function generate(array $options): ?array
    {
        if (!$this->isAvailable()) return null;

        try {
            $prompt = (string) ($options['prompt'] ?? '');
            if ($prompt === '') return null;

            $squadId = (string) ($options['squad_id'] ?? $this->mintSquadId());
            $subTasks = $this->resolveSubTasks($options, $prompt);
            if ($subTasks === []) return null;

            $tierMap = $this->resolveTierMap($options);
            $checkpointStore = $this->resolveCheckpointStore($options);

            $costAccumulator = ['total' => 0.0];

            $dispatcher = $this->buildAgentDispatcher($options, $costAccumulator);

            $orchestrator = new PeerOrchestrator(
                agentDispatcher:  $dispatcher,
                approvalHandler:  $this->resolveApprovalHandler($options),
                logger:           $this->logger ?? new \Psr\Log\NullLogger(),
                checkpointStore:  $checkpointStore,
                output:           null,
                maxCostUsd:       $this->resolveMaxCost($options),
            );

            $result = $orchestrator->run(
                squadId:  $squadId,
                subTasks: $subTasks,
                tierMap:  $tierMap,
                inputs:   (array) ($options['inputs'] ?? []),
            );

            // Render the squad output as the final pipeline step's text
            // — there's a synthesize step at the end of most decompositions,
            // and if there isn't, the last successful step's output is the
            // best "summary" we have.
            $text = $this->extractFinalText($result);
            if ($text === '') return null;

            $completed = $result->completedStepNames();
            $rolesOut = [];
            foreach ($result->roles as $role) {
                $rolesOut[] = [
                    'name'     => $role->name,
                    'provider' => $role->provider,
                    'model'    => $role->model,
                    'tier'     => $role->tier->value,
                ];
            }

            return [
                'text'  => $text,
                'model' => 'squad:' . substr(sha1(json_encode($result->modelTierSnapshot) ?: ''), 0, 8),
                'usage' => [
                    'input_tokens'                => 0,
                    'output_tokens'               => 0,
                    'cache_read_input_tokens'     => 0,
                    'cache_creation_input_tokens' => 0,
                ],
                'cost_usd'    => (float) $costAccumulator['total'],
                'turns'       => count($completed),
                'stop_reason' => null,
                'squad'       => [
                    'squad_id'        => $squadId,
                    'step_count'      => count($subTasks),
                    'completed'       => $completed,
                    'roles'           => $rolesOut,
                    'checkpoint_path' => $checkpointStore !== null && isset($options['checkpoint_dir'])
                        ? rtrim((string) $options['checkpoint_dir'], '/') . '/' . $squadId . '.json'
                        : null,
                    'mailbox_log'     => $result->mailbox !== null
                        ? array_map(fn($m) => method_exists($m, 'toArray') ? $m->toArray() : (array) $m, $result->mailbox->log())
                        : [],
                ],
            ];
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning('SquadBackend error: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Build the agent dispatcher closure consumed by `PeerOrchestrator`.
     * Constructs a fresh `Agent` per step using the role's provider /
     * model; the orchestrator owns session-id continuity so the same
     * role's prompt cache survives across steps.
     *
     * Tuple return shape feeds back to the orchestrator's cost ledger:
     *   { output: string, cost_usd: float, blackboard?: array }
     *
     * @param array<string,mixed> $options
     * @param array{total:float}  $costAccumulator  passed by reference so
     *                                              the parent generate()
     *                                              can read back total
     */
    protected function buildAgentDispatcher(array $options, array &$costAccumulator): callable
    {
        $logger = $this->logger;
        return function (SquadDispatchRequest $req) use (&$costAccumulator, $options, $logger) {
            $providerName = $req->provider;
            $providerConfig = (array) ($options['provider_configs'][$providerName] ?? []);

            try {
                $provider = ProviderRegistry::createForHost($providerName, [
                    'api_key'  => $providerConfig['api_key']  ?? null,
                    'base_url' => $providerConfig['base_url'] ?? null,
                    'model'    => $req->model,
                    'region'   => $providerConfig['region']   ?? null,
                    'extra'    => [],
                ]);
            } catch (\Throwable $e) {
                if ($logger) {
                    $logger->warning('Squad dispatch: provider construction failed', [
                        'provider' => $providerName,
                        'model'    => $req->model,
                        'role'     => $req->role->name,
                        'error'    => $e->getMessage(),
                    ]);
                }
                return ['output' => '', 'cost_usd' => 0.0];
            }

            $agent = new Agent([
                'provider'   => $provider,
                'max_tokens' => (int) ($options['max_tokens'] ?? 4096),
                'max_turns'  => max(1, (int) ($options['max_turns'] ?? 6)),
                'tools'      => [],
            ]);

            if ($req->systemPrompt !== null && $req->systemPrompt !== '') {
                $agent->withSystemPrompt($req->systemPrompt);
            }

            // Attach SDK 1.0.0 peer-messaging tools so this step's LLM
            // can ask/tell/inbox other roles mid-step. Mailbox comes
            // from the orchestrator on the request. We swallow construct
            // errors (e.g. an older SDK without these classes) — the
            // step still runs, it just can't talk to peers.
            if ($req->mailbox !== null) {
                $this->attachPeerTools($agent, $req);
            }

            $result = $agent->run($req->prompt);
            $cost = (float) ($result->totalCostUsd ?? 0.0);
            $costAccumulator['total'] += $cost;

            return [
                'output'   => $result->text(),
                'cost_usd' => $cost,
            ];
        };
    }

    /**
     * @param array<string,mixed> $options
     * @return SubTask[]
     */
    protected function resolveSubTasks(array $options, string $prompt): array
    {
        if (isset($options['subtasks']) && is_array($options['subtasks']) && $options['subtasks'] !== []) {
            $out = [];
            foreach ($options['subtasks'] as $st) {
                if ($st instanceof SubTask) { $out[] = $st; continue; }
                if (!is_array($st) || empty($st['name']) || empty($st['prompt'])) continue;
                $out[] = new SubTask(
                    name:           (string) $st['name'],
                    role:           (string) ($st['role'] ?? $st['name']),
                    prompt:         (string) $st['prompt'],
                    difficulty:     DifficultyClass::tryFrom((string) ($st['difficulty'] ?? 'moderate')) ?? DifficultyClass::MODERATE,
                    dependsOn:      array_values(array_map('strval', (array) ($st['depends_on'] ?? []))),
                    requiresReview: (bool) ($st['requires_review'] ?? false),
                    systemPrompt:   isset($st['system_prompt']) ? (string) $st['system_prompt'] : null,
                    templateRef:    isset($st['template_ref']) ? (string) $st['template_ref'] : null,
                    parallelGroup:  isset($st['parallel_group']) ? (string) $st['parallel_group'] : null,
                );
            }
            return $out;
        }

        return (new TaskDecomposer())->decompose($prompt);
    }

    /**
     * @param array<string,mixed> $options
     */
    protected function resolveTierMap(array $options): ModelTierMap
    {
        $raw = $options['tier_map'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return new ModelTierMap();
        }
        $map = new ModelTierMap();
        foreach ($raw as $bandKey => $entry) {
            $band = DifficultyClass::tryFrom((string) $bandKey);
            if ($band === null || !is_array($entry)) continue;
            $provider = (string) ($entry['provider'] ?? '');
            $model    = (string) ($entry['model'] ?? '');
            if ($provider === '' || $model === '') continue;
            $map = $map->with($band, $provider, $model);
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $options
     */
    protected function resolveCheckpointStore(array $options): ?SquadCheckpointStore
    {
        $dir = $options['checkpoint_dir'] ?? null;
        if (!is_string($dir) || $dir === '') return null;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            if ($this->logger) {
                $this->logger->warning('Squad: checkpoint_dir not writable, disabling checkpoints', ['dir' => $dir]);
            }
            return null;
        }
        return new SquadCheckpointStore($dir);
    }

    /**
     * @param array<string,mixed> $options
     */
    protected function resolveMaxCost(array $options): ?float
    {
        $v = $options['max_cost_usd'] ?? null;
        if ($v === null) return null;
        $v = (float) $v;
        return $v > 0 ? $v : null;
    }

    /**
     * @param array<string,mixed> $options
     */
    protected function resolveApprovalHandler(array $options): ?callable
    {
        if (isset($options['approval_handler']) && is_callable($options['approval_handler'])) {
            return $options['approval_handler'];
        }
        if (!empty($options['require_approval'])) {
            // Without a real HITL UI bound here, default to auto-deny so
            // the agent doesn't silently execute mutations that needed
            // human review.
            return fn () => false;
        }
        return null;
    }

    /**
     * @param object $squadResult `SquadResult`
     */
    protected function extractFinalText(object $squadResult): string
    {
        $pipelineResult = $squadResult->pipelineResult ?? null;
        if ($pipelineResult === null) return '';

        $results = $pipelineResult->getStepResults();
        if ($results === []) return '';

        // Prefer the *last* completed step — it's typically the
        // synthesize / verify step which already has the rolled-up
        // answer. Fall back to concatenating all completed outputs
        // when the pipeline ended early.
        $lastCompleted = null;
        $completedOutputs = [];
        foreach ($results as $r) {
            if ($r->status !== StepStatus::COMPLETED) continue;
            $completedOutputs[$r->stepName] = (string) ($r->output ?? '');
            $lastCompleted = $r;
        }
        if ($lastCompleted !== null) {
            $text = (string) ($lastCompleted->output ?? '');
            if ($text !== '') return $text;
        }
        if ($completedOutputs === []) return '';
        $parts = [];
        foreach ($completedOutputs as $name => $out) {
            if ($out === '') continue;
            $parts[] = "## {$name}\n\n{$out}";
        }
        return implode("\n\n", $parts);
    }

    /**
     * Wire SDK 1.0.0 peer tools (`peer_ask`, `peer_send`, `peer_inbox`)
     * onto the per-step `Agent`. Silently no-ops when the SDK classes
     * aren't on the classpath (older SDK pinned) so the rest of the
     * dispatch still works.
     */
    protected function attachPeerTools(Agent $agent, SquadDispatchRequest $req): void
    {
        $classes = [
            '\\SuperAgent\\Squad\\Tools\\PeerAskTool',
            '\\SuperAgent\\Squad\\Tools\\PeerSendTool',
            '\\SuperAgent\\Squad\\Tools\\PeerInboxTool',
        ];
        foreach ($classes as $cls) {
            if (!class_exists($cls)) continue;
            try {
                $tool = new $cls($req->mailbox, $req->role->name);
                $agent->addTool($tool);
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->debug('SquadBackend: skipping peer tool ' . $cls . ': ' . $e->getMessage());
                }
            }
        }
    }

    protected function mintSquadId(): string
    {
        try {
            return 'sq_' . bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return 'sq_' . dechex((int) (microtime(true) * 1000));
        }
    }
}
