<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The flow context handed to every flow body — the object that exposes the
 * cross-CLI primitives ("同一套原语，跨 CLI 协作"):
 *
 *   $flow->agent($prompt, $opts)         one cross-CLI call (+ structured output)
 *   $flow->call($prompt, $opts)          a *deferred* call for parallel()/pipeline()
 *   $flow->parallel([$a, $b, ...])       barrier; agent-calls run concurrently
 *   $flow->pipeline($items, ...$stages)  per-item, per-stage concurrent
 *   $flow->gate($name, $check, $opts)    acceptance checkpoint (fallback/relay)
 *   $flow->council($claim, $lenses)      perspective-diverse verify (vote)
 *   $flow->log(), $flow->phase()         narration
 *   $flow->budget, $flow->args, $flow->SKIP
 *
 * Resume, budgeting and the call-ledger are all funneled through one private
 * {@see dispatch()} so they behave identically whether a call originated from
 * agent(), parallel() or a pipeline stage. Actual CLI execution is delegated to
 * an injected batch runner, letting the engine swap a sequential runner for a
 * true process pool without changing this class.
 */
final class Flow
{
    public readonly Skip $SKIP;

    /** Logical position used to align resume against the prior ledger. */
    private int $cursor = 0;

    /** Once a resumed prefix diverges, no later call is served from cache. */
    private bool $resumeBroken = false;

    private string $currentPhase = '';

    /**
     * @param array<string, mixed> $args
     * @param callable(list<AgentCall>): list<AgentResult> $batchRunner
     * @param array<string, list<callable>> $listeners  event => callbacks
     */
    public function __construct(
        public array $args,
        public readonly Budget $budget,
        private $batchRunner,
        private readonly CallLedger $ledger,
        private array $listeners = [],
        private ?LoggerInterface $logger = null,
    ) {
        $this->SKIP = Skip::instance();
        $this->logger ??= new NullLogger();
    }

    // ── primitives ────────────────────────────────────────────────

    /**
     * Run one agent call now and return its value: a validated array (when a
     * schema was given), the raw string (no schema), or {@see Skip} on failure.
     *
     * @param array<string, mixed> $opts
     */
    public function agent(string $prompt, array $opts = []): mixed
    {
        $call = $this->makeCall($prompt, $opts);
        $results = $this->dispatch([$call]);
        return $results[0]->value;
    }

    /**
     * Build a deferred call for use inside parallel()/pipeline(). Nothing runs
     * until the batch executes — that's what lets a fan-out run concurrently.
     *
     * @param array<string, mixed> $opts
     */
    public function call(string $prompt, array $opts = []): AgentCall
    {
        return $this->makeCall($prompt, $opts);
    }

    /**
     * Barrier fan-out. Each item is either a deferred {@see AgentCall} / spec
     * array (these run concurrently as one batch) or a plain closure (run
     * in-process). Results come back positionally; a closure that throws → null;
     * an agent call that fails schema → {@see Skip}. Use {@see keep()} to strip both.
     *
     * @param list<AgentCall|array<string,mixed>|callable> $thunks
     * @return list<mixed>
     */
    public function parallel(array $thunks): array
    {
        $results = array_fill(0, count($thunks), null);
        $calls = [];
        $owner = [];

        foreach ($thunks as $i => $thunk) {
            if ($thunk instanceof AgentCall) {
                $calls[] = $thunk;
                $owner[] = $i;
            } elseif (is_array($thunk) && (isset($thunk['prompt']) || isset($thunk[0]))) {
                $calls[] = $this->specToCall($thunk);
                $owner[] = $i;
            } elseif (is_callable($thunk)) {
                try {
                    $results[$i] = $thunk();
                } catch (\Throwable $e) {
                    $this->logger->warning('parallel thunk threw', ['error' => $e->getMessage()]);
                    $results[$i] = null;
                }
            }
        }

        if ($calls !== []) {
            $batch = $this->dispatch($calls);
            foreach ($owner as $k => $i) {
                $results[$i] = $batch[$k]->value;
            }
        }

        return $results;
    }

    /**
     * Run each item through every stage. A stage receives
     * (prevResult, originalItem, index) and returns either a value or a deferred
     * {@see AgentCall} (`$flow->call(...)`). Calls produced within one stage run
     * concurrently across items. A stage that throws drops that item to null and
     * skips its remaining stages.
     *
     * @param list<mixed> $items
     * @param callable ...$stages
     * @return list<mixed>
     */
    public function pipeline(array $items, callable ...$stages): array
    {
        $items = array_values($items);
        $live = $items;                       // current value carried per item
        $dropped = array_fill(0, count($items), false);

        foreach ($stages as $stage) {
            $calls = [];
            $owner = [];
            foreach ($items as $i => $original) {
                if ($dropped[$i]) {
                    continue;
                }
                try {
                    $out = $stage($live[$i], $original, $i);
                } catch (\Throwable $e) {
                    $this->logger->warning('pipeline stage threw', ['index' => $i, 'error' => $e->getMessage()]);
                    $dropped[$i] = true;
                    $live[$i] = null;
                    continue;
                }
                if ($out instanceof AgentCall) {
                    $calls[] = $out;
                    $owner[] = $i;
                } else {
                    $live[$i] = $out;
                }
            }
            if ($calls !== []) {
                $batch = $this->dispatch($calls);
                foreach ($owner as $k => $i) {
                    $live[$i] = $batch[$k]->value;
                }
            }
        }

        return $live;
    }

    /**
     * Acceptance checkpoint. `$check` returns truthy to pass. On failure an
     * optional `fallback`/`relay` callable can supply a substitute value; a
     * `required` gate with no substitute throws {@see GateFailedException}.
     *
     * @param array<string, mixed> $opts  fallback|relay|required|pass_reason|fail_reason
     */
    public function gate(string $name, callable $check, array $opts = []): GateResult
    {
        $reason = '';
        try {
            $passed = (bool) $check();
        } catch (\Throwable $e) {
            $passed = false;
            $reason = $e->getMessage();
        }

        $value = null;
        $relayed = false;
        if ($passed) {
            $reason = (string) ($opts['pass_reason'] ?? 'accepted');
        } else {
            $reason = $reason !== '' ? $reason : (string) ($opts['fail_reason'] ?? 'gate check failed');
            $branch = $opts['fallback'] ?? $opts['relay'] ?? null;
            if (is_callable($branch)) {
                try {
                    $value = $branch();
                    $relayed = true;
                } catch (\Throwable $e) {
                    $this->logger->warning('gate fallback threw', ['gate' => $name, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->ledger->append([
            'kind' => 'gate',
            'label' => $name,
            'signature' => '',
            'passed' => $passed,
            'relayed' => $relayed,
            'layer' => 'gate',
            'cost_usd' => 0,
        ]);
        // A gate consumes one ledger seq slot, so the logical cursor must advance
        // too — otherwise every agent call AFTER a gate would misalign against the
        // prior ledger on resume and miss the cache. Gates are free to re-run.
        $this->cursor++;
        $this->emit('gate', ['name' => $name, 'passed' => $passed, 'relayed' => $relayed, 'reason' => $reason]);

        if (!$passed && ($opts['required'] ?? false) && !$relayed) {
            throw new GateFailedException("Gate \"{$name}\" failed: {$reason}");
        }

        return new GateResult($name, $passed, $reason, $value, $relayed);
    }

    /**
     * Perspective-diverse verification: judge a claim through several distinct
     * lenses concurrently and tally a majority vote. Each lens can be pinned to a
     * different CLI by passing `['lens' => 'security', 'backend' => 'codex_cli']`
     * specs instead of bare strings.
     *
     * @param list<string|array<string,mixed>> $lenses
     * @return array{votes: list<mixed>, pass: int, total: int, passed: bool}
     */
    public function council(string $claim, array $lenses): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['verdict', 'reason'],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => ['pass', 'fail']],
                'reason' => ['type' => 'string'],
            ],
        ];

        $calls = [];
        foreach ($lenses as $lens) {
            $name = is_array($lens) ? (string) ($lens['lens'] ?? 'reviewer') : (string) $lens;
            $opts = ['role' => 'critic', 'label' => 'council:' . $name, 'schema' => $schema];
            if (is_array($lens)) {
                foreach (['backend', 'provider', 'model'] as $k) {
                    if (isset($lens[$k])) {
                        $opts[$k] = $lens[$k];
                    }
                }
            }
            $calls[] = $this->makeCall(
                "Evaluate the following strictly through the \"{$name}\" lens. "
                . "Return verdict=pass only if it holds up.\n\n{$claim}",
                $opts
            );
        }

        $results = $this->dispatch($calls);
        $votes = [];
        $pass = 0;
        foreach ($results as $r) {
            $v = $r->isSkip() ? null : $r->value;
            $votes[] = $v;
            if (is_array($v) && ($v['verdict'] ?? '') === 'pass') {
                $pass++;
            }
        }

        $total = count($lenses);
        return ['votes' => $votes, 'pass' => $pass, 'total' => $total, 'passed' => $total > 0 && $pass * 2 >= $total];
    }

    /**
     * Federation primitive: hand a sub-flow off to superagent's (cross-model)
     * SmartFlow and return its result. SuperAICore fans out across CLIs; the
     * superagent leg fans out across model providers — either one of its own
     * flows (pass a `$target` name → "自行分发") or a flow whose structure you
     * author inline (pass `['spec' => [...]]` → "按照本项目的指示分发").
     *
     * Sugar over `agent('', ['delegate' => $target, ...])`; the call flows
     * through the same ledger / budget / resume machinery as any agent call, so
     * delegated spend federates into the parent budget and a delegated call can
     * sit inside `parallel()` / `pipeline()` like any other.
     *
     * @param array<string, mixed> $opts  delegate|spec|flow_args|delegate_provider|delegate_model|…
     */
    public function delegate(string $target = '', array $opts = []): mixed
    {
        if ($target !== '' && !isset($opts['delegate']) && !isset($opts['spec'])) {
            $opts['delegate'] = $target;
        }
        $opts['label'] = $opts['label'] ?? ('delegate:' . ($target !== '' ? $target : (string) ($opts['delegate'] ?? 'spec')));
        $call = $this->makeCall((string) ($opts['prompt'] ?? ''), $opts);
        return $this->dispatch([$call])[0]->value;
    }

    public function log(string $message): void
    {
        $this->logger->info('[flow] ' . $message);
        $this->emit('log', ['message' => $message, 'phase' => $this->currentPhase]);
    }

    public function phase(string $title): void
    {
        $this->currentPhase = $title;
        $this->emit('phase', ['title' => $title]);
    }

    /** Strip nulls and SKIP sentinels from a parallel()/pipeline() result. */
    public function keep(array $values): array
    {
        return array_values(array_filter(
            $values,
            static fn ($v) => $v !== null && !Skip::isSkip($v)
        ));
    }

    public function skip(): Skip
    {
        return $this->SKIP;
    }

    public function currentPhase(): string
    {
        return $this->currentPhase;
    }

    // ── internals ─────────────────────────────────────────────────

    /**
     * The one place resume + budget + ledger are applied. Given an ordered batch
     * of calls: serve the unchanged prefix from the prior ledger, run the rest
     * via the injected batch runner (concurrently when a pool is wired), then
     * write ledger entries in logical order so positions line up on the next
     * resume.
     *
     * @param list<AgentCall> $calls
     * @return list<AgentResult>
     */
    private function dispatch(array $calls): array
    {
        $n = count($calls);
        $base = $this->cursor;
        $sigs = [];
        $priorHit = [];
        $broken = $this->resumeBroken;

        for ($i = 0; $i < $n; $i++) {
            $sigs[$i] = FlowSignature::forCall($calls[$i]);
            if (!$broken) {
                $hit = $this->ledger->matchPrior($base + $i, $sigs[$i]);
                if ($hit !== null) {
                    $priorHit[$i] = $hit;
                } else {
                    $broken = true;
                    $priorHit[$i] = null;
                }
            } else {
                $priorHit[$i] = null;
            }
        }
        $this->resumeBroken = $this->resumeBroken || $broken;

        // Run the live (cache-miss) calls.
        $liveCalls = [];
        $liveMap = [];
        foreach ($calls as $i => $call) {
            if ($priorHit[$i] === null) {
                if ($this->budget->isExhausted()) {
                    throw new BudgetExceededException(
                        "Budget exhausted before \"{$call->label}\": "
                        . json_encode($this->budget->toArray(), JSON_UNESCAPED_SLASHES)
                    );
                }
                $liveMap[$i] = count($liveCalls);
                $liveCalls[] = $call;
            }
        }
        $liveResults = $liveCalls !== [] ? ($this->batchRunner)($liveCalls) : [];

        // Assemble + ledger in logical order.
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($priorHit[$i] !== null) {
                $result = $this->fromCached($priorHit[$i], $calls[$i]);
                $this->ledger->append($this->ledgerEntry($calls[$i], $sigs[$i], $result, cached: true));
                $this->emit('agent', ['label' => $calls[$i]->label, 'cached' => true, 'layer' => $result->layer]);
            } else {
                $result = $liveResults[$liveMap[$i]] ?? $this->errorResult($calls[$i], 'no result');
                $this->budget->record($result->costUsd, $result->totalTokens());
                $this->ledger->append($this->ledgerEntry($calls[$i], $sigs[$i], $result, cached: false));
                $this->emit('agent', [
                    'label' => $calls[$i]->label,
                    'cached' => false,
                    'layer' => $result->layer,
                    'backend' => $result->backend,
                    'model' => $result->model,
                    'cost_usd' => $result->costUsd,
                    'skip' => $result->isSkip(),
                ]);
            }
            $out[] = $result;
        }

        $this->cursor = $base + $n;
        return $out;
    }

    /** @param array<string, mixed> $opts */
    private function makeCall(string $prompt, array $opts): AgentCall
    {
        $label = (string) ($opts['label'] ?? ('agent-' . ($this->cursor + 1)));
        $opts['label'] = $label;
        $opts['phase'] = $opts['phase'] ?? $this->currentPhase;
        return AgentCall::fromOpts($prompt, $opts, $label);
    }

    /** @param array<string, mixed> $spec */
    private function specToCall(array $spec): AgentCall
    {
        if (isset($spec[0])) {
            $prompt = (string) $spec[0];
            $opts = is_array($spec[1] ?? null) ? $spec[1] : [];
        } else {
            $prompt = (string) ($spec['prompt'] ?? '');
            $opts = $spec;
            unset($opts['prompt']);
        }
        return $this->makeCall($prompt, $opts);
    }

    /** @param array<string, mixed> $prior */
    private function fromCached(array $prior, AgentCall $call): AgentResult
    {
        $skip = (bool) ($prior['skip'] ?? false);
        $value = $skip ? Skip::instance() : ($prior['value'] ?? '');
        return new AgentResult(
            value: $value,
            text: (string) ($prior['text'] ?? (is_string($value) ? $value : json_encode($value))),
            layer: (string) ($prior['layer'] ?? 'text'),
            backend: (string) ($prior['backend'] ?? $prior['provider'] ?? ''),
            model: (string) ($prior['model'] ?? ''),
            inputTokens: 0,
            outputTokens: 0,
            costUsd: 0.0,
            valid: !$skip,
            fake: (bool) ($prior['fake'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    private function ledgerEntry(AgentCall $call, string $signature, AgentResult $result, bool $cached): array
    {
        return [
            'kind' => 'agent',
            'label' => $call->label,
            'phase' => $call->phase,
            'signature' => $signature,
            'backend' => $result->backend,
            'model' => $result->model,
            'layer' => $result->layer,
            'input_tokens' => $cached ? 0 : $result->inputTokens,
            'output_tokens' => $cached ? 0 : $result->outputTokens,
            'cost_usd' => $cached ? 0 : round($result->costUsd, 6),
            'cached' => $cached,
            'skip' => $result->isSkip(),
            'valid' => $result->valid,
            'value' => $result->isSkip() ? null : $result->value,
            'text' => $result->text,
            'error' => $result->error,
        ];
    }

    private function errorResult(AgentCall $call, string $error): AgentResult
    {
        return new AgentResult(
            value: $call->schema !== null ? Skip::instance() : '',
            text: '',
            layer: 'none',
            backend: '',
            model: '',
            valid: false,
            error: $error,
        );
    }

    private function emit(string $event, array $data): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($data);
            } catch (\Throwable) {
                // listener errors never break a flow
            }
        }
    }
}
