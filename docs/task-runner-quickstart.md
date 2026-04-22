# TaskRunner Quickstart

**Phase:** B (per `host-spawn-uplift-roadmap.md`)
**Builds on:** Phase A (`StreamingBackend` + `Dispatcher::dispatch(['stream' => true])`).

---

## What it does

`Runner\TaskRunner::run($backend, $prompt, $options)` is a one-call
execution entry point that wraps `Dispatcher::dispatch(['stream' => true, ...])`,
normalizes the result into a typed `TaskResultEnvelope`, and offers two
optional persistence hooks (`prompt_file`, `summary_file`) so hosts can
keep their on-disk debug breadcrumbs without writing the file plumbing
themselves.

Hosts that adopted Phase A's `stream:true` flag can now collapse their
`executeTask()` / `executeClaude()` bodies — typically 100–200 lines of
"build prompt file → spawn → tee log → extract summary → wrap into
result array" — to a single `$runner->run()` call.

---

## Hello world

```php
$envelope = app(\SuperAICore\Runner\TaskRunner::class)->run(
    backend: 'claude_cli',
    prompt:  'Reply with: OK',
);

if ($envelope->success) {
    echo $envelope->summary;        // 'OK'
    echo $envelope->costUsd;        // 0.000123
    echo $envelope->logFile;        // /tmp/superaicore-claude_cli-...-.log
    echo $envelope->durationMs;     // 1521
} else {
    echo $envelope->error;
}
```

---

## Realistic host call

```php
$envelope = app(\SuperAICore\Runner\TaskRunner::class)->run('claude_cli', $prompt, [
    // Where to tee the live stream — Process Monitor 'tail' view follows this.
    'log_file'     => $logFile,

    // Optional debug breadcrumbs (host's existing convention).
    'prompt_file'  => $promptFile,
    'summary_file' => "{$outputDir}/summary.md",

    // Phase A streaming knobs.
    'timeout'      => 7200,        // 2-hour hard cap for long task runs
    'idle_timeout' => 1800,        // 30 min idle
    'mcp_mode'     => 'empty',     // claude only — see streaming-backends.md

    // Phase C hook (no-op today; auto-activates on upgrade).
    'spawn_plan_dir' => $outputDir,

    // Usage attribution + dashboard grouping.
    'task_type'    => 'tasks.run',
    'capability'   => $task->type,
    'user_id'      => auth()->id(),
    'provider_id'  => $providerId,
    'metadata'     => ['task_id' => $task->id],
    'external_label' => "task:{$task->id}",

    // Live UI updates.
    'onChunk' => function (string $chunk, string $stream) use ($taskResult) {
        $taskResult->updateQuietly(['preview' => mb_substr($chunk, -500)]);
    },
]);

if ($envelope->success) {
    $taskResult->update([
        'content'      => $envelope->summary,
        'raw_output'   => $envelope->output,
        'metadata'     => [
            'usage'         => $envelope->usage,
            'cost_usd'      => $envelope->costUsd,
            'shadow_cost'   => $envelope->shadowCostUsd,
            'billing_model' => $envelope->billingModel,
            'model'         => $envelope->model,
            'usage_log_id'  => $envelope->usageLogId,
        ],
        'status'       => 'success',
        'duration_seconds' => intdiv($envelope->durationMs, 1000),
        'finished_at'  => now(),
    ]);
} else {
    $taskResult->update([
        'status'        => 'error',
        'error_message' => $envelope->error,
        'finished_at'   => now(),
    ]);
}
```

---

## Options reference

`TaskRunner::run()` accepts every option `Dispatcher::dispatch()` does
(see `streaming-backends.md`), plus three TaskRunner-only convenience
hooks:

| Key | Type | Notes |
|---|---|---|
| `prompt_file` | ?string | Write the prompt body here before dispatch. Best-effort — write failure does not break the run. |
| `summary_file` | ?string | Write `$envelope->output` here on success when text is non-empty. Skipped when text is empty. |
| `spawn_plan_dir` | ?string | **Active in Phase C.** When set, TaskRunner hands off to `AgentSpawn\Pipeline` after the first pass — Pipeline checks for `_spawn_plan.json` in this dir, runs the parallel children (Phase 2), and re-invokes the backend with a consolidation prompt (Phase 3). Returns the merged envelope with `spawnReport` populated. No-op when no plan is found / backend opts out (claude/kiro/copilot/superagent). See `docs/spawn-plan-protocol.md`. |

All other options (`backend`, `prompt`, `model`, `system`, `messages`,
`max_tokens`, `provider_config`, `log_file`, `timeout`, `idle_timeout`,
`mcp_mode`, `mcp_config_file`, `external_label`, `onChunk`, `task_type`,
`capability`, `user_id`, `provider_id`, `metadata`, `scope`, `scope_id`)
pass straight through to Dispatcher.

The runner forces `stream: true` on every call — there's no point in
using `TaskRunner` for a non-streaming dispatch (the wrapping value adds
nothing for a single-shot call). Hosts that want the one-shot path
should call `Dispatcher::dispatch()` directly without the `stream` flag.

---

## TaskResultEnvelope

```php
class TaskResultEnvelope
{
    public readonly bool $success;            // exit_code === 0 AND text !== ''
    public readonly int $exitCode;
    public readonly string $output;           // raw streamed text
    public readonly string $summary;          // same as output in Phase B; Phase C may post-process
    public readonly array $usage;             // backend-specific token / credit breakdown
    public readonly ?float $costUsd;
    public readonly ?float $shadowCostUsd;
    public readonly ?string $billingModel;    // 'usage' | 'subscription'
    public readonly ?string $model;
    public readonly ?string $backend;
    public readonly int $durationMs;
    public readonly ?string $logFile;
    public readonly ?int $usageLogId;         // ai_usage_logs row id (Phase B addition)
    public readonly ?array $spawnReport;      // Phase C — null today
    public readonly ?string $error;           // populated when success === false

    public static function failed(int $exitCode = 1, ?string $logFile = null, ?string $error = null, ?string $backend = null): self;
    public function toArray(): array;
}
```

**Why `success` requires non-empty text:** Phase A's `stream()` returns
the envelope with `text=''` when the subprocess exited cleanly but the
parser couldn't extract a final result event (malformed output, premature
exit, model refused). Treating `exit_code === 0 && text === ''` as
success would cause hosts to overwrite a TaskResult with a blank
summary. The `success` flag conservatively fails in that case so hosts
can distinguish "the model spoke" from "the binary returned 0 but the
output was unusable".

---

## Migration: SuperTeam `executeClaude()` before / after

**Before** (~150 lines):

```php
protected function executeClaude(Task $task, string $outputDir, string $timestamp, array $options): array
{
    $logsDir = storage_path('logs/team-factory');
    if (!is_dir($logsDir)) mkdir($logsDir, 0755, true);

    // Build prompt
    $resultId = $options['result-id'] ?? null;
    if ($resultId) {
        $taskResult = TaskResult::find($resultId);
        // ... merge cross-references
    }
    if ($pptResultId) { $prompt = PromptBuilder::buildPptPrompt(...); }
    elseif ($infographicResultId) { ... }
    // ... 6 more elseif branches
    else { $prompt = PromptBuilder::buildPrompt($task, $outputDir, $options); }

    $promptFile = "{$logsDir}/{$task->project_name}-{$task->type}-{$timestamp}.prompt.md";
    file_put_contents($promptFile, $prompt);
    $logFile = "{$logsDir}/{$task->project_name}-{$task->type}-{$timestamp}.log";

    // Resolve model + backend
    $backend = ClaudeRunner::resolveTaskBackend($task, $this->providerId(), $options['backend'] ?? null);
    $activeProviderId = ClaudeRunner::getActiveProviderId($this->userId(), $this->providerId(), $backend);
    $taskModel = $options['override-model'] ?? AiModelSetting::resolveModel(...);

    // Build & spawn process
    $process = ClaudeRunner::buildTaskProcess(
        $task, $promptFile, $logFile, $projectRoot,
        $taskModel, $this->userId(), $this->providerId(), $backend
    );
    $process->setTimeout(null);
    $process->setIdleTimeout(null);
    $process->run();

    $exitCode = $process->getExitCode();
    $output = file_exists($logFile) ? file_get_contents($logFile) : '';
    file_put_contents($logFile, "\n---\nExit code: {$exitCode}\n", FILE_APPEND);

    $summary = ClaudeRunner::extractSummary($backend, $output, $logFile);
    $usage = ClaudeRunner::extractUsage($backend, $output, $taskModel);

    return [
        'success'   => $exitCode === 0,
        'exit_code' => $exitCode,
        'output'    => $output,
        'summary'   => $summary,
        'usage'     => $usage,
    ];
}
```

**After** (~30 lines):

```php
protected function executeClaude(Task $task, string $outputDir, string $timestamp, array $options): array
{
    $logsDir = storage_path('logs/team-factory');
    $backend = ClaudeRunner::resolveTaskBackend($task, $this->providerId(), $options['backend'] ?? null);
    $activeProviderId = ClaudeRunner::getActiveProviderId($this->userId(), $this->providerId(), $backend);
    $taskModel = $options['override-model']
        ?: AiModelSetting::resolveModel($task->type, 'global', null, $activeProviderId, $backend);

    $envelope = app(\SuperAICore\Runner\TaskRunner::class)->run(
        $this->dispatcherBackendFor($backend),
        $this->buildPromptFor($task, $outputDir, $options),
        [
            'model'          => $taskModel,
            'log_file'       => "{$logsDir}/{$task->project_name}-{$task->type}-{$timestamp}.log",
            'prompt_file'    => "{$logsDir}/{$task->project_name}-{$task->type}-{$timestamp}.prompt.md",
            'spawn_plan_dir' => $outputDir,
            'mcp_mode'       => 'empty',
            'timeout'        => 7200,
            'idle_timeout'   => 1800,
            'task_type'      => 'tasks.run',
            'capability'     => $task->type,
            'user_id'        => $this->resolveUsageUserId($task),
            'provider_id'    => $activeProviderId,
            'metadata'       => ['task_id' => $task->id, 'project_name' => $task->project_name],
        ],
    );

    return $envelope->toArray();   // for the host's existing array-shaped consumer
}
```

The `dispatcherBackendFor()` translation (`claude` → `claude_cli` etc.)
already lives in SuperTeam; `executeViaDispatcher()` becomes redundant
once `runBackend()`'s `match` collapses to a single `TaskRunner::run()`
call for every backend.

---

## Phase C — spawn-plan integration (now active)

When `spawn_plan_dir` is set, TaskRunner hands the first-pass envelope
to `AgentSpawn\Pipeline`, which:

1. Checks for `_spawn_plan.json` in `spawn_plan_dir` (and the cwd).
2. If present AND the backend participates (codex/gemini today):
   - Runs the parallel children via `Orchestrator`.
   - Re-invokes the same backend with the consolidation prompt
     (`BackendCapabilities::consolidationPrompt()`).
   - Returns the merged envelope (success from consolidation, output =
     first pass + consolidation, costs summed, `spawnReport` populated).
3. If absent / backend opts out → returns null → TaskRunner keeps the
   first-pass envelope unchanged.

Backends that participate today: `codex_cli`, `gemini_cli`. Adding a
new CLI to the protocol requires implementing `spawnPreamble()` +
`consolidationPrompt()` on its `BackendCapabilities` and registering a
`ChildRunner` with `Orchestrator::forBackend()`. See
`docs/spawn-plan-protocol.md` for the full spec.

Hosts that adopted Phase B's `spawn_plan_dir` option pre-Phase-C
automatically get this behavior on upgrade — no call-site change.
