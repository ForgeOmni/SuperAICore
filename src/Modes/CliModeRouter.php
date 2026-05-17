<?php

declare(strict_types=1);

namespace SuperAICore\Modes;

use Psr\Log\LoggerInterface;
use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAgent\Modes\ModeRouter;
use SuperAgent\Squad\TeamRegistry;

/**
 * SuperAICore's `ModeRouter` subclass — adds two host-specific
 * capabilities on top of the SDK's basic `mode-name → orchestrator`
 * dispatch:
 *
 *   1. **Leaf provider tags** (`cli:X` / `sdk:X`). When a step's
 *      `provider` field starts with one of these prefixes (instead
 *      of being a mode name), the router falls through to
 *      `CrossLayerDispatcher` which handles the actual leaf
 *      dispatch. This is how a single dispatcher closure can serve
 *      both "recurse into another mode" and "execute against a
 *      named backend".
 *   2. **TeamRegistry lookups**. When options carry `team: <name>`,
 *      the router pre-loads the team's `SquadPlan` and threads it
 *      into the orchestrator's options so the orchestrator doesn't
 *      need to know about the registry directly.
 *
 * Loose coupling preserved: the SDK's `ModeRouter` only knows about
 * mode-name dispatch and `ModeContext`. The host extends; the SDK
 * doesn't care.
 */
class CliModeRouter extends ModeRouter
{
    public function __construct(
        private readonly CrossLayerDispatcher $crossLayer,
        private readonly ?TeamRegistry $teamRegistry = null,
        LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
        parent::__construct($logger);
    }

    /**
     * Dispatch override: recognise `cli:` / `sdk:` leaf tags and
     * route them through the cross-layer dispatcher (which already
     * speaks them). Mode-name dispatches fall through to the parent.
     *
     * @param array<string,mixed> $options
     */
    public function dispatch(string $mode, string $task, ModeContext $context, array $options = []): ModeResult
    {
        // Leaf-tag short-circuit.
        if (str_starts_with($mode, 'cli:') || str_starts_with($mode, 'sdk:')) {
            $leaf = $this->crossLayer->dispatch([
                'provider'   => $mode,
                'prompt'     => $task,
                'options'    => $options,
                'metadata'   => $options['metadata'] ?? [],
            ]);
            $cost = (float) ($leaf['cost_usd'] ?? 0.0);
            $context->costLedger->record($mode, $cost, null, $leaf['model'] ?? null);
            return new ModeResult(
                text:    (string) ($leaf['output'] ?? ''),
                costUsd: $cost,
                mode:    $mode,
                trace:   $context->modeStack,
                modeSpecific: [
                    'backend' => $leaf['backend'] ?? null,
                    'model'   => $leaf['model']   ?? null,
                    'usage'   => $leaf['usage']   ?? null,
                ],
            );
        }

        // Team-pre-load: when the caller passed `team: name` and the
        // mode is 'squad', load the team plan from the registry so
        // the orchestrator gets a fully-formed `SquadPlan`.
        if (isset($options['team']) && is_string($options['team']) && $this->teamRegistry !== null) {
            $plan = $this->teamRegistry->load((string) $options['team']);
            if ($plan !== null && !isset($options['plan'])) {
                $options['plan'] = $plan;
            }
        }

        return parent::dispatch($mode, $task, $context, $options);
    }
}
