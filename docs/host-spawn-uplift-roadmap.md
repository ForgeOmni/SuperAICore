# Roadmap — Lift Host Spawn Path Upstream

**Status:** Phase A complete (2026-04-21) — see Unreleased CHANGELOG entry. B / C / D / E pending.
**Driver:** SuperTeam currently maintains ~700 lines of CLI-spawn / log-tee /
spawn-plan / usage-extract logic that should live in SuperAICore so every
host (SuperTeam, Shopify Autopilot, future hosts) inherits the same
production-grade execution path. Goal of this roadmap: **adding a new CLI
to SuperAICore should require zero host code changes** — and the existing
three battle-tested engines (claude / codex / gemini) should migrate so
hosts shrink from "owns spawn" to "calls one method".

---

## 1. Current state matrix

| Capability | SuperAICore (today) | SuperTeam (today) | Target home |
|---|---|---|---|
| One-shot `Backend::generate(prompt) → text` | ✅ all 9 backends | — | upstream (keep) |
| Streaming execution + tee log file | ❌ | ✅ `ClaudeRunner::buildClaudeProcess` (`-p --output-format stream-json --verbose`, shell-pipe to log) | **upstream** |
| Process Monitor row (ai_processes) | ✅ `MonitoredProcess` trait (used by AgentRunners) | partial — host spawn path doesn't register | **upstream — make universal** |
| Live `onText` callback during stream | partial — `KiroAgentRunner` etc. emit chunks | ✅ `executeSuperAgent` (NdjsonStreamingHandler) | **upstream — generalize** |
| Configurable timeouts (hard + idle) | hardcoded `$this->timeout = 60` per backend | ✅ 7200s hard / 1800s idle on claude | **upstream — config + per-call override** |
| MCP empty injection (`--mcp-config <empty> --strict-mcp-config`) | ❌ | ✅ host writes `mcp-empty.json` per run | **upstream — `mcp_mode: empty\|inherit\|file`** |
| Spawn-plan Phase 1 (preamble in prompt) | ❌ | ✅ inside `PromptBuilder` per backend | **upstream — `BackendCapabilities::spawnPreamble()`** |
| Spawn-plan Phase 2 (parallel fanout) | ✅ `AgentSpawn\Orchestrator` | calls upstream | upstream (keep) |
| Spawn-plan Phase 3 (consolidation re-call) | ❌ | ✅ `runConsolidationPass()` | **upstream — `AgentSpawn\Pipeline::consolidate()`** |
| Output-format-aware extractors (stream-json / jsonl / plain) | ✅ `CliOutputParser` (single-shot only) | ✅ `TaskResultParser` (handles streamed jsonl) | **upstream — extend `CliOutputParser`** |
| Provider env injection (KIRO_API_KEY etc.) | ✅ `ProviderEnvBuilder` (0.6.2) | calls upstream | upstream (done) |
| Usage recording with cache-aware shadow | ✅ `UsageRecorder` (0.6.2/0.6.5) | calls upstream | upstream (done) |
| ai_usage_logs row deduplication | ❌ relies on caller idempotency | ✅ host uses `_usage_recorded` sentinel | **upstream — idempotency key** |

---

## 2. New upstream APIs (proposed)

### 2.1 `Contracts\StreamingBackend` — sibling of `Backend`

```php
namespace SuperAICore\Contracts;

interface StreamingBackend extends Backend
{
    /**
     * Execute long-running prompt, streaming chunks via $onChunk callback
     * as they arrive (used to tee a log file and refresh UI previews).
     * Returns the final result envelope (same shape as generate()) plus a
     * `stream_log` field pointing at the on-disk capture.
     *
     * @param array $options  same keys as generate(), plus:
     *   - log_file?: string         where to tee the raw stream (auto-named if absent)
     *   - timeout?: int             seconds — overrides backend default
     *   - idle_timeout?: int        seconds — overrides backend default
     *   - mcp_mode?: 'empty'|'inherit'|'file'   default 'inherit'
     *   - mcp_config_file?: string  required when mcp_mode=file
     *   - external_label?: string   for Process Monitor row
     *   - onChunk?: callable(string $chunk, string $stream): void
     *   - usage_record?: bool       default true — Dispatcher writes ai_usage_logs
     *   - usage_idempotency_key?: string   skip duplicate row if same key recently written
     *
     * @return array|null
     *   {text, model, usage, cost_usd, shadow_cost_usd, billing_model,
     *    stream_log, duration_ms, exit_code}
     */
    public function stream(array $options): ?array;
}
```

Backwards compatible: backends opt in by implementing `StreamingBackend`.
`Backend::generate()` keeps working — for short calls (test_connection,
routed AI services). `Dispatcher::dispatch()` gains a `stream: true` flag
that prefers `stream()` when the resolved backend implements the interface.

### 2.2 `Runner\TaskRunner` — universal "run one task" entry point

```php
namespace SuperAICore\Runner;

class TaskRunner
{
    /**
     * Execute a single task: prompt → backend (streaming preferred) → tee
     * log → extract summary + usage → handle spawn plan → return canonical
     * result envelope. Hosts replace ~150 lines of spawn glue with a
     * single call.
     */
    public function run(string $backend, string $prompt, array $options = []): TaskResultEnvelope
}

class TaskResultEnvelope
{
    public bool $success;
    public int $exitCode;
    public string $output;       // raw streamed log
    public string $summary;      // extracted final assistant text
    public array $usage;         // tokens + cost + shadow
    public ?array $spawnReport;  // when AgentSpawn\Pipeline ran
    public string $logFile;
    public ?int $usageLogId;     // ai_usage_logs row id when recorded
}
```

`TaskRunner` orchestrates:
1. Resolve backend via `BackendRegistry`
2. Pick `stream()` if `StreamingBackend` else `generate()`
3. Wire `onChunk` to a `TeeLogger` (writes to disk + invokes user callback)
4. After exit: call `CliOutputParser` for the engine, populate envelope
5. If output dir contains `_spawn_plan.json` AND `BackendCapabilities`
   says `supportsSubAgents() == false`, hand off to `AgentSpawn\Pipeline`
6. Record `ai_usage_logs` exactly once (with idempotency key)

### 2.3 `AgentSpawn\Pipeline` — full three-phase orchestration

Today `AgentSpawn\Orchestrator` handles Phase 2 only (parallel fanout).
Move Phase 1 + 3 upstream:

```php
namespace SuperAICore\AgentSpawn;

class Pipeline
{
    /**
     * Phase 1 — caller prepended `BackendCapabilities::spawnPreamble()`
     *           to the prompt; we just kicked off a backend run that ended.
     * Phase 2 — detect _spawn_plan.json (multiple candidate paths), fan out.
     * Phase 3 — consolidation re-invocation against the same backend.
     */
    public function maybeRun(
        string $backend,
        string $outputDir,
        TaskResultEnvelope $firstPass,
        array $options,
    ): ?TaskResultEnvelope;
}
```

`BackendCapabilities` gains:

```php
/**
 * Preamble appended to prompts for backends without native sub-agents.
 * Instructs the model to emit `_spawn_plan.json` as Phase 1 output.
 * Returns '' for backends with native sub-agent primitives.
 */
public function spawnPreamble(string $outputDir): string;

/**
 * Backend-specific consolidation prompt template — same across hosts so
 * SuperTeam, Shopify Autopilot, etc. produce identical 摘要.md / 思维导图.md.
 * Hosts can override via config('super-ai-core.spawn.consolidation_template').
 */
public function consolidationPrompt(SpawnPlan $plan, array $report, string $outputDir): string;
```

### 2.4 MCP injection — `mcp_mode` knob on streaming backend

Each `*CliBackend::stream()` honors an `mcp_mode` option:

| mcp_mode | Behavior |
|---|---|
| `inherit` (default) | CLI uses its globally configured MCP set |
| `empty` | Write a temp `{"mcpServers":{}}` and pass `--mcp-config` + strict flag — **prevents Claude from spawning 30+ MCPs that block process exit** |
| `file` | Use the explicit `mcp_config_file` path |

Backends that can't disable MCP (or don't have MCP) ignore the knob.

Host's current host-managed `mcp-empty.json` lifecycle becomes a one-line
option pass.

### 2.5 Log streaming — `Support\TeeLogger`

Reusable across `TaskRunner`, `MonitoredProcess`, `AgentSpawn\Orchestrator`:

```php
namespace SuperAICore\Support;

class TeeLogger
{
    public function __construct(string $logFile);
    public function write(string $chunk): void;     // append to disk + bytes counter
    public function close(): void;
    public function bytesWritten(): int;
    public function path(): string;
}
```

### 2.6 Idempotency on `UsageRecorder::record()`

Add optional `idempotency_key` parameter. When set, the recorder checks
the last 60s for a row with the same key on `ai_usage_logs`; if found,
returns the existing id instead of writing a duplicate. Hosts that
double-instrument (rare but possible) stop double-counting. Implementation:

```sql
ALTER TABLE ai_usage_logs ADD idempotency_key VARCHAR(80) NULL;
ALTER TABLE ai_usage_logs ADD INDEX (idempotency_key, created_at);
```

---

## 3. Migration phases (atomic, version-agnostic)

Each phase is a self-contained, releasable unit. The maintainer assigns
the actual version number at release time — phase order matters, version
numbers don't. A phase can ship alone or bundled with neighbors as the
maintainer judges fit. Nothing breaks between phases; hosts opt in by
calling new APIs.

### Phase A — `StreamingBackend` + `TeeLogger`

- Add `Contracts\StreamingBackend` interface.
- Add `Support\TeeLogger`.
- Implement `ClaudeCliBackend::stream()` first — port from
  `ClaudeRunner::buildClaudeProcess()`. Honors `mcp_mode`, `timeout`,
  `idle_timeout`, `onChunk`. Tees to log file, parses stream-json result
  events at end, returns standard envelope.
- Implement `CodexCliBackend::stream()` and `GeminiCliBackend::stream()`
  the same way.
- Implement `KiroCliBackend::stream()` and `CopilotCliBackend::stream()` —
  these CLIs don't emit structured streams, so `onChunk` fires per
  buffered output chunk and the parser uses `parseOutput()` at end.
- Extend `Dispatcher::dispatch()` with `stream: true` flag → prefers
  `stream()` if backend implements it; falls back to `generate()`.
- No host change required.
- Release-note seed: "All CLIs now support live streaming + Process
  Monitor registration via `Dispatcher::dispatch(['stream' => true, ...])`."

### Phase B — `Runner\TaskRunner` + `TaskResultEnvelope`

- Depends on Phase A.
- Wraps the streaming dispatch with summary extraction, usage recording,
  log file management, configurable output directory.
- Spawn-plan integration is opt-in: if `options['spawn_plan_dir']` is
  set, `TaskRunner` calls `AgentSpawn\Pipeline::maybeRun()` before
  returning (no-op until Phase C lands).
- Hosts can now collapse `executeClaude()` to one call:
  ```php
  $envelope = app(TaskRunner::class)->run($backend, $prompt, [
      'log_file' => $logFile,
      'output_dir' => $outputDir,
      'spawn_plan_dir' => $outputDir,
      'mcp_mode' => 'empty',
      'timeout' => 7200,
      'idle_timeout' => 1800,
      'task_type' => 'tasks.run',
      'capability' => $task->type,
      'user_id' => $userId,
      'provider_id' => $providerId,
      'metadata' => ['task_id' => $task->id],
  ]);
  ```
- No host change required to keep working — `TaskRunner` is purely
  additive. Hosts adopt it on their schedule.
- Release-note seed: "One-line task execution via `TaskRunner` — replaces
  ~150 lines of host spawn glue."

### Phase C — `AgentSpawn\Pipeline` (Phase 1 + 3 of the protocol)

- Depends on Phase B (uses `TaskRunner` for the consolidation re-call).
- Move `BackendCapabilities::spawnPreamble()` and
  `consolidationPrompt()` from host into the existing
  `Capabilities\{Codex,Gemini}Capabilities` classes.
- `Pipeline` auto-detects `_spawn_plan.json` in `output_dir` after first
  pass, fans out via existing `Orchestrator`, then re-invokes the same
  backend with `consolidationPrompt()`.
- Host's `maybeRunSpawnPlan` + `runConsolidationPass` deletable (~150
  lines) once host upgrades.
- Release-note seed: "Spawn-plan protocol fully internal — codex/gemini
  hosts no longer need to implement Phase 1/3 themselves."

### Phase D — Idempotency + cleanup

- Independent of A/B/C — can ship in any order relative to them.
- Add `idempotency_key` column + index to `ai_usage_logs` (migration).
- `UsageRecorder::record()` honors `idempotency_key`.
- `Dispatcher::dispatch()` auto-generates a key (`"{backend}:{external_label}:{run_id}"`)
  so hosts that accidentally double-record stop double-counting without
  any code change.
- Deprecate `_usage_recorded` host sentinel (no-op upstream now).

### Phase E — Stable contract freeze

- Conditional: ships once A + B + C have soaked in production for at
  least one downstream host.
- `StreamingBackend` + `TaskRunner` + `Pipeline` + `TeeLogger` declared
  stable. SemVer applies — breaking changes only at the next major.
- Update README + docs to make `TaskRunner` the recommended entry
  point.

---

## 4. Backward compatibility guarantees

- `Backend::generate()` never deprecated — short routed calls (test
  connections, vision API hops, embeddings) keep using it.
- `MonitoredProcess::runMonitored()` and `runMonitoredAndRecord()`
  unchanged. `TaskRunner` builds on them, doesn't replace them — hosts
  with custom spawn requirements (e.g. unusual exec wrappers) can keep
  using the trait directly.
- `Dispatcher::dispatch()` keeps current shape; `stream: true` is opt-in.
- `AgentSpawn\Orchestrator` is the implementation detail of `Pipeline`;
  remains public for hosts that want Phase 2 only.
- `ai_usage_logs.idempotency_key` is nullable — old rows + non-keyed
  callers are fine.

---

## 5. Host migration impact

Once SuperTeam adopts the release that bundles Phase C (and B, which it
depends on):

| File | LOC delta | Notes |
|---|---|---|
| `app/Console/Commands/ExecuteTask.php` | -250 | `executeClaude` / `executeViaDispatcher` collapse to `TaskRunner::run()` |
| `app/Console/Commands/ExecuteTask.php::maybeRunSpawnPlan` + `runConsolidationPass` | -130 | deleted (in `Pipeline`) |
| `app/Services/ClaudeRunner.php::buildClaudeProcess/CodexProcess/GeminiProcess` | -180 | replaced by `StreamingBackend::stream()` |
| `app/Services/TaskResultParser.php` | -80 | replaced by `CliOutputParser` extensions |
| `app/Services/Ppt/ClaudeStreamUsageParser.php` | unchanged | PPT parser stays; reads same stream-json log file |
| **Total host shrink** | **~640 lines** | from current ~1200 lines of spawn-related code |

New CLIs added to SuperAICore after Phase C ships: **0 host changes**.

---

## 6. Test plan

For each phase:

- **Unit**: each backend's `stream()` method against captured fixtures
  (the same fixtures `parseJson`/`parseJsonl` use today).
- **Integration**: spin up a test Laravel app via Orchestra Testbench,
  dispatch a small `Reply with: OK` prompt against every CLI that's
  available on the runner, assert envelope shape + `ai_usage_logs` row.
- **Regression**: keep current `tests/` (250+ tests as of 0.6.2) green.
- **Manual smoke**: SuperTeam dev env runs one task per backend per
  phase; verify (a) `/super-ai-core/usage` shows the row, (b)
  `/super-ai-core/processes` shows the registrar row, (c) log file
  exists and is readable.

---

## 7. Open questions (resolve during Phase A PR)

1. **Streaming for non-CLI backends** — `AnthropicApiBackend` /
   `OpenAiApiBackend` could implement `StreamingBackend` too via SSE.
   Bundle into Phase A or split as Phase A′? (Lean: split — current host
   doesn't need API-streaming, keep Phase A scoped to CLIs.)
2. **Spawn preamble localization** — host currently has prompt strings
   in zh-CN / en / fr. Should preamble be localized? (Lean: no — the
   model reads English instructions equally well; preamble is internal,
   not user-facing.)
3. **Output dir convention** — host uses
   `storage/logs/team-factory/<project>-<type>-<ts>.log`. Should
   `TaskRunner` accept a dir + autoname, or take an explicit path?
   (Lean: explicit path, matches current host behavior.)
4. **Process Monitor "external_label" naming** — currently free-form
   strings like `task:42`. Standardize on `<host>:<entity>:<id>` so
   multi-host installs (SuperTeam + Shopify Autopilot sharing one
   ai_processes table) don't collide?

---

## 8. Quick-reference: filing-cabinet of changes

```
SuperAICore/
  src/
    Contracts/
      StreamingBackend.php                  (new — Phase A)
      BackendCapabilities.php               (extend — Phase C)
    Backends/
      ClaudeCliBackend.php                  (+ stream() — Phase A)
      CodexCliBackend.php                   (+ stream() — Phase A)
      GeminiCliBackend.php                  (+ stream() — Phase A)
      KiroCliBackend.php                    (+ stream() — Phase A)
      CopilotCliBackend.php                 (+ stream() — Phase A)
    Capabilities/
      CodexCapabilities.php                 (+ spawnPreamble + consolidationPrompt — Phase C)
      GeminiCapabilities.php                (+ spawnPreamble + consolidationPrompt — Phase C)
    Runner/
      TaskRunner.php                        (new — Phase B)
      TaskResultEnvelope.php                (new — Phase B)
    AgentSpawn/
      Pipeline.php                          (new — Phase C)
      Orchestrator.php                      (unchanged)
      SpawnPlan.php                         (unchanged)
    Services/
      Dispatcher.php                        (+ stream flag — Phase A)
      CliOutputParser.php                   (+ stream-json variants — Phase A)
      UsageRecorder.php                     (+ idempotency_key — Phase D)
    Support/
      TeeLogger.php                         (new — Phase A)
  database/migrations/
    YYYY_MM_DD_NNNNNN_add_idempotency_key_to_ai_usage_logs.php  (Phase D — date stamped at release)
  docs/
    streaming-backends.md                   (new — Phase A — usage examples)
    task-runner-quickstart.md               (new — Phase B)
    spawn-plan-protocol.md                  (new — Phase C — formal spec)
```

---

## 9. Glossary (so future PR reviewers stay aligned)

- **Streaming backend** — emits chunks live, returns final envelope.
- **One-shot backend** — single request/response (current `generate()`).
- **Spawn plan** — `_spawn_plan.json` written by Phase 1 model output.
- **Pipeline** — three-phase agent-spawn orchestration.
- **Tee log** — the on-disk capture of streamed CLI output.
- **External label** — host-defined string identifying a Process Monitor
  row (e.g. `task:42`, `ppt:job:7:strategist`).
- **Envelope** — standardized result shape across all backends.

---

End of roadmap.

When a phase lands, append a one-liner here so future maintainers can
see at a glance what's done — version is whatever the maintainer chose
for that release:

- 2026-04-21 — Phase A code-complete, sitting in `[Unreleased]` CHANGELOG.
  StreamingBackend interface + TeeLogger + StreamableProcess trait + 5
  CLI `stream()` impls + Dispatcher `stream:true` flag + 22 new tests.
  Awaiting maintainer to pick a version and tag.
