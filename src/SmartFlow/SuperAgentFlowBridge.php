<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs a {@see Delegation} against the SuperAgent SDK's (cross-model) SmartFlow
 * engine — the federation bridge. This is what lets a SuperAICore cross-CLI flow
 * dispatch a sub-task to superagent, which then fans out across model providers
 * itself: either one of its OWN flows (mode 'named' → superagent self-dispatches)
 * or a flow whose structure SuperAICore authored (mode 'spec' → superagent runs
 * to SuperAICore's instructions).
 *
 * Execution is in-process via the SDK classes (same composer autoload), so the
 * sub-flow's ledger, budget, resume and — crucially — rehearsal all work; a
 * rehearsed SuperAICore flow rehearses the delegated SDK flow too, end-to-end at
 * zero cost. When the SDK is not installed the bridge reports an actionable
 * error instead of throwing.
 */
final class SuperAgentFlowBridge
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * True when the SuperAgent SDK SmartFlow engine is available to delegate to.
     */
    public function available(): bool
    {
        return class_exists(\SuperAgent\SmartFlow\FlowEngine::class)
            && class_exists(\SuperAgent\SmartFlow\FlowOptions::class);
    }

    /**
     * Run the delegated sub-flow and return a normalized result.
     *
     * @return array{ok: bool, value: mixed, status: string, cost_usd: float,
     *               input_tokens: int, output_tokens: int, run_id: string,
     *               error: ?string, model: string}
     */
    public function run(Delegation $delegation, bool $rehearse): array
    {
        if (!$this->available()) {
            return $this->failure('SuperAgent SDK SmartFlow is not installed (require forgeomni/superagent ^1.1.0)');
        }

        try {
            $def = $this->resolveDefinition($delegation);
        } catch (\Throwable $e) {
            return $this->failure('could not resolve delegated flow: ' . $e->getMessage());
        }
        if ($def === null) {
            return $this->failure("delegated flow '{$delegation->name}' not found in the SuperAgent SDK registry");
        }

        $optsClass = \SuperAgent\SmartFlow\FlowOptions::class;
        /** @var object $opts */
        $opts = new $optsClass(rehearse: $rehearse);
        // Steer superagent's internal routing per SuperAICore's instructions.
        if ($delegation->provider !== null) {
            $opts->defaultProvider = $delegation->provider;
        }
        if ($delegation->model !== null) {
            $opts->defaultModel = $delegation->model;
        }
        if ($delegation->concurrency !== null) {
            $opts->concurrency = $delegation->concurrency;
        }
        if ($delegation->budgetUsd !== null) {
            $opts->budgetUsd = $delegation->budgetUsd;
        }

        try {
            $engineClass = \SuperAgent\SmartFlow\FlowEngine::class;
            $engine = new $engineClass();
            $result = $engine->run($def, $delegation->args, $opts);
        } catch (\Throwable $e) {
            $this->logger->error('SmartFlow delegation to superagent failed', ['error' => $e->getMessage()]);
            return $this->failure('superagent flow run failed: ' . $e->getMessage());
        }

        $ledger = is_array($result->ledger ?? null) ? $result->ledger : [];

        return [
            'ok' => ($result->status ?? 'failed') === 'completed',
            'value' => $result->value ?? null,
            'status' => (string) ($result->status ?? 'failed'),
            'cost_usd' => (float) ($ledger['cost_usd'] ?? 0.0),
            'input_tokens' => (int) ($ledger['input_tokens'] ?? 0),
            'output_tokens' => (int) ($ledger['output_tokens'] ?? 0),
            'run_id' => (string) ($result->runId ?? ''),
            'error' => $result->error ?? null,
            'model' => $delegation->model ?? ($delegation->provider ?? 'smartflow'),
        ];
    }

    /**
     * Resolve a SuperAgent {@see \SuperAgent\SmartFlow\FlowDefinition} for the
     * delegation — either compiled from SuperAICore's inline spec, or looked up
     * by name in the SDK's own flow registry.
     */
    private function resolveDefinition(Delegation $delegation): ?object
    {
        if ($delegation->mode === Delegation::MODE_SPEC && $delegation->spec !== null) {
            $loaderClass = \SuperAgent\SmartFlow\YamlFlowLoader::class;
            if (!class_exists($loaderClass)) {
                throw new \RuntimeException('SuperAgent YamlFlowLoader unavailable');
            }
            $loader = new $loaderClass();
            // A raw YAML string was passed through under __yaml__.
            if (isset($delegation->spec['__yaml__']) && is_string($delegation->spec['__yaml__'])) {
                return $loader->loadString($delegation->spec['__yaml__'], 'superaicore-delegated');
            }
            return $loader->compile($delegation->spec, 'superaicore-delegated');
        }

        $registryClass = \SuperAgent\SmartFlow\FlowRegistry::class;
        if (!class_exists($registryClass)) {
            throw new \RuntimeException('SuperAgent FlowRegistry unavailable');
        }
        $registry = new $registryClass();
        return $registry->get($delegation->name);
    }

    /**
     * @return array{ok: bool, value: mixed, status: string, cost_usd: float,
     *               input_tokens: int, output_tokens: int, run_id: string,
     *               error: ?string, model: string}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'value' => null,
            'status' => 'failed',
            'cost_usd' => 0.0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'run_id' => '',
            'error' => $error,
            'model' => 'smartflow',
        ];
    }
}
