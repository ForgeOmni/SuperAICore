<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Per-run knobs for {@see FlowEngine::run()}.
 *
 * - rehearse:      use the deterministic zero-cost fake runner ("演练") — no CLI
 *                  is invoked, output is schema-conforming stub data.
 * - dryRun:        like rehearse but keeps the ledger in memory (no file written).
 * - resumeRunId:   load a prior run's ledger and replay its unchanged prefix.
 * - concurrency:   max simultaneous parallel workers (process pool).
 * - budgetUsd / budgetTokens: hard ceilings enforced by {@see Budget}.
 * - defaultBackend / defaultModel: fallback CLI/model for calls without one.
 */
final class FlowOptions
{
    /**
     * @param (callable(list<AgentCall>): list<AgentResult>)|null $batchRunner
     * @param array<string, list<callable>> $listeners
     */
    public function __construct(
        public bool $rehearse = false,
        public bool $dryRun = false,
        public ?string $resumeRunId = null,
        public ?int $concurrency = null,
        public ?float $budgetUsd = null,
        public ?int $budgetTokens = null,
        public ?string $defaultBackend = null,
        public ?string $defaultModel = null,
        public ?string $runId = null,
        public ?string $ledgerDir = null,
        public $batchRunner = null,
        public array $listeners = [],
    ) {}

    /**
     * True when this run must avoid all real CLI calls (rehearse or dry-run).
     */
    public function isFake(): bool
    {
        return $this->rehearse || $this->dryRun;
    }
}
