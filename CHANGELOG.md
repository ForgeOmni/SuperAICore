# Changelog

All notable changes to `forgeomni/superaicore` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.6] — 2026-04-21

Bundles all five phases of the **host-spawn-uplift** roadmap (`docs/host-spawn-uplift-roadmap.md`) in one release: live-streaming CLI execution (Phase A), one-call task orchestration (Phase B), three-phase spawn-plan emulation (Phase C), `ai_usage_logs` idempotency (Phase D), and a formal SemVer contract freeze (Phase E). Hosts that want to stay on `Backend::generate()` are unaffected — every new path is purely additive. The one technical interface addition (`BackendCapabilities::spawnPreamble` + `consolidationPrompt` from Phase C) is shielded for downstream extenders by the new `Capabilities\Concerns\BackendCapabilitiesDefaults` trait shipped in Phase E.

**Migration required:** `php artisan migrate` adds the nullable `idempotency_key` column + composite index for Phase D. No config changes. No host code changes — existing call sites keep working.

### Added — Phase E (API stability + forward-compat trait)

**`docs/api-stability.md` — formal SemVer contract**
- Lists every API now considered stable: `Contracts\StreamingBackend`, `Support\TeeLogger`, `Backends\Concerns\StreamableProcess`, `Runner\TaskRunner`, `Runner\TaskResultEnvelope`, `AgentSpawn\Pipeline`, `Contracts\BackendCapabilities` (with the trait below for future-proofing), `Capabilities\SpawnConsolidationPrompt::build()` (signature only — prompt text remains tunable), `Services\Dispatcher::dispatch()` option keys + return shape, `Services\UsageRecorder::record()` shape, `Services\EloquentUsageRepository::IDEMPOTENCY_WINDOW_SECONDS`, and `Models\AiUsageLog` columns.
- Documents the deprecation policy: deprecated APIs ship in minor release N with a pointer to the replacement, coexist for at least two minor releases (N+1, N+2), and only get removed at the next major.
- Lists what's intentionally NOT stable so hosts know which surfaces they can lean on and which to avoid: concrete CLI backend internals, `Runner\AgentRunner` family (older API), `AgentSpawn\Orchestrator` direct usage, `AgentSpawn\ChildRunner` interface, Blade views, CLI command output formats, internal index/column types.
- Includes a **pre-soak caveat** documenting that the maintainer chose to declare stability before the originally-planned production-soak window. If a Phase A/B/C/D bug forces a backward-incompatible fix, the maintainer will bump major rather than retroactively rewrite history.

**`Capabilities\Concerns\BackendCapabilitiesDefaults` — forward-compat trait**
- New trait providing no-op default implementations of any `BackendCapabilities` methods added after the Phase E freeze (currently `spawnPreamble()` and `consolidationPrompt()` from Phase C, both returning `''`).
- Hosts implementing custom `BackendCapabilities` should `use BackendCapabilitiesDefaults;` to inherit safe defaults for any method SuperAICore adds in future minor releases — the host class stays satisfying the interface without adding the new method itself. Bundled `*Capabilities` classes do NOT use the trait (they provide real implementations); it exists exclusively for downstream extension safety.
- Maintainer commitment: when SuperAICore adds another `BackendCapabilities` method in a future release, a no-op default lands in this trait in the SAME release so hosts that adopted the trait get safe semantics for free.

**README.md — `TaskRunner` promoted to recommended entry point**
- New "PHP quick start" section leads with a realistic `TaskRunner::run()` example showing log-file tee, MCP injection, spawn-plan handoff, idempotency-via-external_label, and live `onChunk` UI updates. The previous one-shot `Dispatcher::dispatch()` example moves below it as the "short call" path for non-task workloads (test connections, vision routing, embeddings).
- Cross-links to the four phase docs (`task-runner-quickstart.md`, `streaming-backends.md`, `spawn-plan-protocol.md`, `idempotency.md`) plus the new `api-stability.md`.

### Added — Phase D (idempotency_key + 60s dedup window)

**Migration: `ai_usage_logs.idempotency_key VARCHAR(80) NULL`**
- New migration `2026_04_21_000002_add_idempotency_key_to_ai_usage_logs.php` adds the column + a composite index `(idempotency_key, created_at)` covering the "find a matching row in the last N seconds" lookup the repository runs on every record() with a key set. Run `php artisan migrate` after upgrading.
- Nullable + non-unique by design — old rows + non-keyed callers (test_connection probes, ad-hoc scripts) coexist fine.

**`EloquentUsageRepository::record()` honors `idempotency_key`**
- When the input data has `idempotency_key` set, the repository checks `ai_usage_logs` for a row with the same key written within `IDEMPOTENCY_WINDOW_SECONDS` (default 60). If found, returns that row's id instead of inserting a duplicate.
- 60s is long enough to absorb host-side accidental double-records (Dispatcher writing + a host that also calls UsageRecorder for the same turn) but short enough that two genuinely separate runs that happen to share a key don't get falsely deduped.
- `EloquentUsageRepository::IDEMPOTENCY_WINDOW_SECONDS` is `public const` so callers can read it (e.g. for cleanup window math) without depending on hardcoded literals.

**`UsageRecorder::record()` accepts and forwards `idempotency_key`**
- Threaded straight through to `UsageTracker → UsageRepository`. Hosts that want explicit dedup control pass their own key (e.g. internal job id, run UUID).

**`Dispatcher::dispatch()` auto-generates `idempotency_key` from `external_label`**
- New `Dispatcher::resolveIdempotencyKey()` picks the key with this precedence:
  1. Explicit `options['idempotency_key']` — caller wins. `false` opts out of auto-gen entirely.
  2. Auto-derived from `options['external_label']` when present: `"{backend}:{external_label}"` — stable across the duplicate dispatches that come from a host's accidental double-record, distinct across legitimately separate runs (each task has its own external_label).
  3. Otherwise null — no dedup, every record() inserts a row.
- Truncated to 80 chars to fit the column.
- This is the load-bearing safety net: hosts that haven't fully migrated to TaskRunner often call both `Dispatcher::dispatch()` AND their own `UsageRecorder::record()` for the same logical turn (PPT ClaudeStreamUsageParser is a known case). After Phase D those duplicate calls auto-collapse to one row without any host code change.

**`AiUsageLog` model: `idempotency_key` added to fillable + property docblock**

### Added — Phase C (AgentSpawn\Pipeline)

**`AgentSpawn\Pipeline` — three-phase spawn-plan orchestration**
- New service registered as a singleton in `SuperAICoreServiceProvider`. `Pipeline::maybeRun($backend, $outputDir, $firstPass, $options)` detects `_spawn_plan.json` in the output directory after a first-pass run, fans out N child CLI processes via the existing `AgentSpawn\Orchestrator`, then re-invokes the same backend with the consolidation prompt from `BackendCapabilities::consolidationPrompt()` and returns a merged `TaskResultEnvelope` with `spawnReport` populated.
- Lifts ~150 lines (`maybeRunSpawnPlan` + `runConsolidationPass`) that downstream hosts (SuperTeam, etc.) used to maintain themselves. Once a host upgrades and removes those methods, adding a new CLI that needs spawn-plan emulation requires zero host changes — only an upstream `BackendCapabilities` + `ChildRunner` implementation.
- Returns null when (a) the first pass failed, (b) no plan file was found, or (c) the backend opts out of the protocol (claude/kiro/copilot/superagent return `''` from `consolidationPrompt`). In each case `TaskRunner` keeps the first-pass envelope unchanged.
- Plan-file location: checks `$outputDir/_spawn_plan.json` first, then the cwd as fallback. Found-but-misplaced plans are moved to the canonical location before `SpawnPlan::fromFile()` reads them, so subsequent runs don't pick up a stale plan from cwd.
- Cost / duration merge: when the consolidation pass succeeds, `costUsd` / `shadowCostUsd` / `durationMs` accumulate first pass + consolidation. `summary` is the consolidation text alone (the user-facing answer); `output` is both passes joined by `\n--- consolidation ---\n`.
- Test seam: optional `$orchestratorFactory` constructor arg lets unit tests stub Phase 2 without spawning real CLI children. Production code defaults to `Orchestrator::forBackend()`.

**`Capabilities\SpawnConsolidationPrompt` — default Phase 3 prompt template**
- Lifted from SuperTeam's `runConsolidationPass()` so every downstream host produces identical `摘要.md` / `思维导图.md` / `流程图.md` trees regardless of which CLI ran. Used by `CodexCapabilities` + `GeminiCapabilities` `consolidationPrompt()` implementations. Hosts with different filename conventions should NOT extend this class — instead build their own consolidation prompt and feed it directly into `TaskRunner::run()` as a separate dispatch.

**`BackendCapabilities::spawnPreamble()` + `consolidationPrompt()`**
- Two new interface methods. `CodexCapabilities` + `GeminiCapabilities` return non-empty strings (the `PREAMBLE` constants `transformPrompt()` was already injecting + the consolidation template). `ClaudeCapabilities` (native sub-agents) / `KiroCapabilities` / `CopilotCapabilities` / `SuperAgentCapabilities` return `''` to opt out of the protocol.
- `transformPrompt()` is unchanged; the new method simply exposes the same preamble text for direct callers (Pipeline, host code that wants to render the preamble separately from the user prompt).
- **Note for hosts implementing custom `BackendCapabilities`:** this is technically an interface addition and will require those hosts to add the two methods. Returning `''` from both opts out of the protocol cleanly.

**`TaskRunner` activates Pipeline transparently when `spawn_plan_dir` is set**
- The Phase B no-op stub becomes load-bearing. Hosts that wired `spawn_plan_dir` pre-Phase-C automatically get the new behavior on upgrade.
- `TaskRunner::__construct` now accepts an optional `Pipeline` arg (second positional). Backward-compatible: omitting it makes `spawn_plan_dir` a no-op rather than throwing, so legacy callers keep working.

### Added — Phase B (TaskRunner)

**`Runner\TaskRunner` — one-call task execution wrapper around Dispatcher**
- New service registered as a singleton in `SuperAICoreServiceProvider`. `app(TaskRunner::class)->run($backend, $prompt, $options)` drives `Dispatcher::dispatch(['stream' => true, ...])`, normalizes the result into a typed `TaskResultEnvelope`, and offers two optional persistence hooks (`prompt_file`, `summary_file`) so hosts keep their on-disk debug breadcrumbs without writing the file plumbing themselves.
- Hosts that adopted Phase A's `stream:true` flag can now collapse their `executeTask()` / `executeClaude()` bodies (typically 100–200 lines of "build prompt file → spawn → tee log → extract summary → wrap into result array") to a single `$runner->run()` call. Sample migration in `docs/task-runner-quickstart.md`.
- Forwards every Dispatcher option transparently (model, system, provider_config, log_file, timeout, idle_timeout, mcp_mode, mcp_config_file, external_label, onChunk, task_type, capability, user_id, provider_id, metadata, scope, scope_id) and consumes only three runner-only keys: `prompt_file`, `summary_file`, `spawn_plan_dir`.
- `spawn_plan_dir` is wired today as a no-op forward-compat hook — Phase C ships `AgentSpawn\Pipeline` and TaskRunner will activate the fan-out + consolidation transparently. Hosts can pass the option now and pick up the behavior on upgrade with no call-site change.
- Conservative success semantics: `$envelope->success === true` requires `exit_code === 0` AND `text !== ''`. Phase A's `stream()` returns the envelope with `text=''` when the subprocess exited cleanly but the parser couldn't extract a final result event (malformed output, premature exit, model refused). Treating that as success would cause hosts to overwrite a TaskResult with a blank summary — TaskRunner conservatively fails so hosts can distinguish "the model spoke" from "the binary returned 0 but the output was unusable".

**`Runner\TaskResultEnvelope` — typed result shape**
- Public-readonly properties for `success` / `exitCode` / `output` / `summary` / `usage` / `costUsd` / `shadowCostUsd` / `billingModel` / `model` / `backend` / `durationMs` / `logFile` / `usageLogId` / `spawnReport` / `error`. Replaces the ad-hoc `['success', 'exit_code', 'output', ...]` arrays each downstream host invented.
- `::failed()` factory for the "Dispatcher couldn't even run the prompt" path (no provider configured, CLI not signed in, backend disabled, empty prompt).
- `toArray()` projection for hosts whose existing storage layer expects an array shape — eases incremental migration.

**`Dispatcher::dispatch()` now surfaces `usage_log_id` on the result**
- Captures the row id `UsageRecorder::record()` returns and stamps it on the dispatch envelope so downstream callers (notably `TaskRunner`) can attach the id to their own envelope without re-querying. Useful for "patch this row with extra metadata once Phase C consolidation finishes" flows and for skipping double-record on hosts that still call UsageRecorder themselves.
- Backward compatible: `usage_log_id` is omitted when no row was written (`UsageTracker` not bound, write failed, `AI_CORE_USAGE_TRACKING=false`). Hosts that don't read the key see no change.

### Added — Phase A (StreamingBackend)

**`Contracts\StreamingBackend` — sibling of `Backend`**
- New interface declaring `stream(array $options): ?array`. Same inputs as `generate()`, plus `log_file` / `timeout` / `idle_timeout` / `mcp_mode` / `mcp_config_file` / `external_label` / `onChunk` / `metadata`. Returns the same envelope `generate()` does, augmented with `log_file`, `duration_ms`, and `exit_code`.
- All five CLI backends implement it in this release: `ClaudeCliBackend` / `CodexCliBackend` / `GeminiCliBackend` / `KiroCliBackend` / `CopilotCliBackend`. The API backends (`AnthropicApiBackend`, `OpenAiApiBackend`, `GeminiApiBackend`) and `SuperAgentBackend` are deferred — they'd need SSE / SDK-internal streaming support that's out of scope for Phase A.

**`Support\TeeLogger` — append-only tee writer for streamed CLI output**
- Used by `stream()` implementations (and any future runner that wants the same "chunk fan-out") to persist the raw stream so the Process Monitor `tail` view, the post-hoc `CliOutputParser`, and the ad-hoc human reader all see the same authoritative bytes. Failure is non-fatal: unwritable paths silently skip disk writes rather than killing the run. `bytesWritten()` / `path()` / `isOpen()` helpers for observability.

**`Backends\Concerns\StreamableProcess` — shared register-tee-wait-end trait**
- Packages the `ProcessRegistrar::start() + TeeLogger + Process::wait(callback) + ProcessRegistrar::end()` dance so each backend's `stream()` body can stay focused on command construction + output parsing. Different from the long-standing `Runner\Concerns\MonitoredProcess` trait in two ways: no `emit()` requirement on the consumer (backends are silent by default; UI updates flow through `$onChunk` only when the caller passes one), and returns a richer envelope bundling captured output + log path + timing.

**`ClaudeCliBackend::parseStreamJson()` — NDJSON walker for `--output-format=stream-json` captures**
- Walks a captured stream-json log for the LAST `result` event and extracts `{text, model, input_tokens, output_tokens, cache_read_input_tokens, cache_creation_input_tokens, total_cost_usd, stop_reason, num_turns, session_id}`. Public for testing — host parsers that already capture the same NDJSON shape (PPT pipelines, task runners) can reuse this without spawning a process.

**`Dispatcher::dispatch([...'stream' => true])` — opt-in streaming route**
- When `options['stream'] === true` and the resolved backend implements `StreamingBackend`, `dispatch()` calls `stream()` instead of `generate()` and forwards `log_file` / `timeout` / `idle_timeout` / `mcp_mode` / `mcp_config_file` / `external_label` / `onChunk` through unchanged. Backends that don't implement the contract fall back to `generate()` silently — callers see the same envelope shape either way (stream-only adds `log_file` + `exit_code`).

**MCP injection knob — `mcp_mode: 'inherit' | 'empty' | 'file'` (ClaudeCliBackend only today)**
- `empty` writes a temp `{"mcpServers":{}}` and passes `--mcp-config <file> --strict-mcp-config` to claude — **required in headless mode when the host has many global MCPs**, otherwise claude keeps spawning them past its final stream event and blocks parent exit. `file` uses an explicit `mcp_config_file`. `inherit` (default) lets claude pick up its global MCP set as usual. Other backends accept the option but no-op today; forward-compat stub so hosts can pass it defensively.

### Changed

- `ClaudeCliBackend` / `CodexCliBackend` / `GeminiCliBackend` / `KiroCliBackend` / `CopilotCliBackend` all gained `implements StreamingBackend` + `use StreamableProcess;`. `generate()` signature / behavior unchanged — no breaking change for existing callers.
- `Dispatcher::dispatch()` now stamps `usage_log_id` on the result envelope when `UsageTracker` writes a row. Existing callers that don't read the key see no change.

### Migration notes

Hosts that currently hand-roll the spawn (build `claude -p --output-format stream-json --verbose ... > log.txt 2>&1`, manage `--mcp-config`, manage timeouts, manage tee, manage usage recording) can replace that entire block with:

- One `Dispatcher::dispatch(['stream' => true, ...])` call (Phase A primitive), OR
- One `app(TaskRunner::class)->run($backend, $prompt, $options)` call (Phase B convenience — recommended for task-execution code paths).

See `docs/streaming-backends.md` and `docs/task-runner-quickstart.md` for full quickstarts. Phase C (`AgentSpawn\Pipeline`) collapses spawn-plan handling further; Phases A + B are the load-bearing primitives.

**Hosts implementing custom `BackendCapabilities`:** the Phase C interface addition (`spawnPreamble` + `consolidationPrompt`) is technically a breaking change. Add `use \SuperAICore\Capabilities\Concerns\BackendCapabilitiesDefaults;` to inherit no-op defaults — the trait keeps the host class satisfying the interface today and through any future minor-release method additions. Bundled `*Capabilities` classes don't use the trait (they provide real implementations).

**Database migration:** run `php artisan migrate` to add the Phase D `idempotency_key` column + composite index. The other phases add no migrations.

### Tests

- 22 new tests in Phase A: `TeeLogger` basics (7), `ClaudeCliBackend::parseStreamJson()` edge cases (5), `StreamingBackend` contract enforcement across all 5 CLIs (10).
- 15 new tests in Phase B: `TaskResultEnvelope` shape (4), `TaskRunner` wrapping contract (11) — empty-prompt failure, dispatcher-null failure, envelope mapping, empty-text-treated-as-failure, prompt_file persistence, summary_file persistence (incl. skipped on empty text), runner-only options stripped, log_file fallback, backend fallback.
- 24 new tests in Phase C: `BackendCapabilities` spawn-protocol contract across all 6 impls (12), `Pipeline::maybeRun` decision tree (6) with stubbed Orchestrator (no real subprocesses), TaskRunner→Pipeline activation (4) — pipeline-absent no-op, pipeline-present activation, pipeline-null first-pass-kept, pipeline-not-called-when-spawn_plan_dir-omitted, pipeline-not-called-when-first-pass-failed.
- 10 new tests in Phase D: migration column present, no-key calls don't dedup, same-key-same-window dedups, distinct-keys-no-dedup, expired-window inserts new row, Dispatcher auto-key from external_label, no-label no-auto-key, explicit-key overrides auto-gen, `idempotency_key:false` opts out of auto-gen, key truncated to 80 chars.
- 2 new tests in Phase E: `BackendCapabilitiesDefaults` trait satisfies the interface, host can selectively override trait defaults per method.
- Full suite: **349 tests / 1034 assertions / 0 failures / 0 skipped** (was 276 / 812 at 0.6.5).

---

## [0.6.5] — 2026-04-21

Small patch tightening the 0.6.2 accounting story. Fixes a Kiro auth-detection bug that reported `not-logged-in` on machines with a valid `~/.kiro/` session, teaches `shadowCalculate()` about Anthropic's cache-token price tiers so heavy-cache Claude calls don't overstate shadow cost by ~10×, prefers the CLI's own `total_cost_usd` over the pricing catalog when the envelope carries it, and tidies the Recent-calls dashboard (Provider / Service column + capability column + filter-state persistence). Also adds an opt-in `MonitoredProcess::runMonitoredAndRecord()` helper so host runners can drop one line after a CLI exits and get a fully-populated `ai_usage_logs` row for free.

No breaking changes.

### Fixed

**Kiro auth: generic detector probed the wrong directory**
- `Services\CliStatusDetector::detectGenericCliAuth()` built the config-dir check from the literal binary name (`~/.kiro-cli/`), but Kiro — like most CLIs — writes its session into `~/.<engine>/` (`~/.kiro/`). Users who had already run `kiro-cli login` saw "not logged in" on `/super-ai-core/providers` anyway. The detector now strips a `-cli` / `_cli` suffix from the binary and probes both the stripped form and the literal, so `kiro-cli` → `~/.kiro/` resolves correctly without breaking engines whose config-dir matches their binary name verbatim. Also adds a `config_dir` field to the auth payload so UI can show *which* directory it found.

### Added

**Cache-aware shadow cost (cache_read 0.1×, cache_write 1.25×)**
- `Services\CostCalculator::shadowCalculate()` now accepts optional `$cacheReadTokens` / `$cacheWriteTokens` parameters and prices them separately from base input. Uses the catalog's explicit `cache_read_input` / `cache_creation_input` rates when present, otherwise applies the standard Anthropic multipliers against the `input` rate (cache reads ≈ 10% of input price, cache writes ≈ 125%). A PPT Strategist run with 500k cache_read + 70k cache_write tokens now reports a shadow cost that matches the Anthropic invoice for the same workload instead of inflating it ~10× by rolling all cache traffic into input.
- `Services\Dispatcher::dispatch()` forwards the cache token counts from `result.usage.cache_read_input_tokens` / `result.usage.cache_creation_input_tokens` (Anthropic wire format) straight into `shadowCalculate()`.
- `Services\UsageRecorder::record()` accepts `cache_read_tokens` / `cache_write_tokens` in the input payload, feeds them to the calculator, and tucks them into `metadata.cache_read_tokens` / `cache_write_tokens` for debugging. Host-side callers (SuperTeam's `ClaudeStreamUsageParser`, `ExecuteTask::recordTaskUsage`, `AiServiceDispatcher::recordUsage`) stop pre-summing cache tokens into `input_tokens` and pass them through these fields instead.

**Dispatcher prefers CLI-reported `total_cost_usd`**
- When the backend's `usage` envelope includes `total_cost_usd` (Claude CLI does, per its `result` event), Dispatcher now uses that figure as the billed cost instead of re-deriving from tokens × rate. Matters because Claude CLI is the only signal that knows whether a given session is billed against a subscription or an API key — the catalog can't infer that from the model id alone. For backends that don't report a billed cost (HTTP APIs mostly), the calculator-derived value still wins. Metadata now records `cost_source: 'cli_envelope' | 'calculator'` so operators can spot which rows came from which path.

**Usage dashboard: Provider / Service + Capability columns**
- Recent-calls table on `/super-ai-core/usage` now surfaces the friendly Provider name (or Service name when it's a service-routed call), plus a dedicated `capability` column. Previously operators had to cross-reference `provider_id` / `service_id` against DB rows to answer "which API key ran this?".
- Filter-state persistence: the `Hide 0-token rows` and `Hide test_connection` toggles were default-on but silently reverted to default on every form submit that un-ticked them (HTML checkboxes don't post when unchecked). A hidden `filters_applied=1` marker now rides along with the form, so an un-ticked box stays un-ticked across reloads.

**`Runner\Concerns\MonitoredProcess::runMonitoredAndRecord()` — opt-in usage recording for runner classes**
- Variant of the existing `runMonitored()` that buffers stdout in memory, parses it with `CliOutputParser` on exit, and writes an `ai_usage_logs` row through `UsageRecorder`. Host runners (anything using the `MonitoredProcess` trait) can drop one call at the bottom of their spawn path and stop hand-rolling parser + recorder glue:
  ```php
  $exitCode = $this->runMonitoredAndRecord(
      process:         $process,
      backend:         'claude_cli',
      commandSummary:  'claude -p "…" --output-format=stream-json',
      externalLabel:   "task:{$task->id}",
      engine:          'claude',  // drives CliOutputParser selection
      context:         [
          'task_type'  => 'ppt.strategist',
          'capability' => 'agent_spawn',
          'user_id'    => auth()->id(),
          'provider_id'=> $providerId,
          'metadata'   => ['ppt_job_id' => 42],
      ],
  );
  ```
- `runMonitored()` (plain-text variant) is untouched — opt-in by design so adopting recording doesn't silently alter the output format a host runner already depends on.
- Parser failures never propagate: if `CliOutputParser` can't match the engine's output shape (common for plain-text Codex / Copilot runs), no row is written and a `debug`-level log note is emitted instead of throwing. The CLI's actual exit code is always returned untouched.

### Changed

**Usage metadata: cost source + cache token counts**
- Every row written via `Dispatcher` or `UsageRecorder` now carries `metadata.cost_source` (`'cli_envelope'` / `'calculator'` / `'caller'`) and — when applicable — `metadata.cache_read_tokens` / `cache_write_tokens`. The dashboard doesn't surface these yet but they're available for drill-downs and invoice reconciliation.

---

## [0.6.2] — 2026-04-21

Patch release closing the "most dashboard rows are 0/0/0" gap on `/super-ai-core/usage` and `/super-ai-core/costs`. Previously every execution that the host app routed through its own runners (`App\Services\ClaudeRunner`, etc.) silently bypassed `ai_usage_logs`, and the few rows that did land there came from the `/providers` "Test connection" button with subscription-billed CLIs that returned `{input_tokens:0, output_tokens:0}` — making the dashboard look empty even during heavy use. This release adds a shadow-cost accounting path so subscription engines surface meaningful USD numbers, a clean `UsageRecorder` API so host runners can drop a one-liner at their call sites, and default dashboard filters that hide the noise.

Also fixes two bugs in the 0.6.1 Kiro integration: the `--model` flag was being dropped (so every call silently went through Kiro's `auto` router regardless of what the user selected) and the seeded model IDs used Claude-CLI dash separators (`claude-sonnet-4-6`) instead of Kiro's dot separators (`claude-sonnet-4.6`), which `kiro-cli` quietly rejects. The picker is now populated **live** from `kiro-cli chat --list-models` and surfaces the 7 non-Anthropic models Kiro supports (DeepSeek / MiniMax / GLM / Qwen) plus the `auto` router.

**Architectural change:** `Services\ProviderTypeRegistry` becomes the single source of truth for provider-type metadata (label / icon / fields / env-var name / allowed backends / needs_api_key). Host apps (notably SuperTeam) previously maintained a parallel `PROVIDER_TYPES` matrix and duplicated the env-injection switch in their own runners — those duplicates can now be replaced by single-line registry lookups. When SuperAICore adds a new API type in the future, host apps pick it up automatically without any code change, only a `composer update`.

No breaking changes. Existing `$dispatcher->dispatch()` callers continue to work; new columns on `ai_usage_logs` are nullable and backfill automatically on new writes.

### Added

**Shadow cost (`shadow_cost_usd`, `billing_model` on `ai_usage_logs`)**
- New migration `2026_04_21_000001_add_shadow_cost_to_ai_usage_logs.php` — adds `shadow_cost_usd decimal(12,6) nullable` and `billing_model varchar(20) nullable` after `cost_usd`. Run `php artisan migrate` on any host using the package.
- `Services\CostCalculator::shadowCalculate(string $model, int $inputTokens, int $outputTokens): float` — computes pay-as-you-go USD for the same tokens regardless of billing model, so a Copilot / Claude-Code-builtin session appears on the Cost Analytics dashboard with a meaningful number instead of a $0 row. Falls through to the SuperAgent `ModelCatalog` for models the host config doesn't enumerate. Returns 0 when the model id is unknown or tokens are zero.
- `Services\Dispatcher::dispatch()` — now stamps `cost_usd`, `shadow_cost_usd`, and `billing_model` onto both the returned result array and the `ai_usage_logs` row. Also forwards `metadata` from the options bag so callers can attach arbitrary context (job id, agent name, etc.) without a custom column.
- `Models\AiUsageLog` — fillable + casts extended for the two new columns.

**`Services\UsageRecorder` — façade for host-side runners**
- Thin wrapper on top of `UsageTracker` + `CostCalculator` that auto-fills `cost_usd`, `shadow_cost_usd`, and `billing_model` from the pricing catalog. Host apps that spawn CLIs directly (e.g. `App\Services\ClaudeRunner`, the PPT stage jobs, `ExecuteTask`) can now drop a single call after each turn:

  ```php
  app(\SuperAICore\Services\UsageRecorder::class)->record([
      'task_type'     => 'ppt.strategist',     // or 'tasks.run', 'ppt.executor', …
      'capability'    => 'agent_spawn',
      'backend'       => 'claude_cli',
      'model'         => 'claude-sonnet-4-5-20241022',
      'input_tokens'  => 12345,
      'output_tokens' => 6789,
      'duration_ms'   => 45000,
      'user_id'       => auth()->id(),
      'metadata'      => ['ppt_job_id' => 42],
  ]);
  ```

  Registered as a singleton in `SuperAICoreServiceProvider::register()`. No-ops when `AI_CORE_USAGE_TRACKING=false` (via the underlying `UsageTracker`).

**`Services\CliOutputParser` — reusable parsers for captured CLI output**
- Static delegates over the backend classes' existing parsers: `::parseClaude()`, `::parseCodex()`, `::parseCopilot()`, `::parseGemini()`. Return the `{text, model, input_tokens, output_tokens, …}` envelope or null when the output doesn't match. Host apps that already capture CLI stdout can extract tokens without constructing a full backend object.

**Dashboard improvements**
- `/super-ai-core/usage` — new "By Task Type" card, "Shadow cost" column on every breakdown table, a per-row billing-model badge (usage / sub), and two toggles above the filters: **Hide 0-token rows** (default on) and **Hide test_connection** (default on). Noise from the `/providers` test button is now filtered by default.
- `/super-ai-core/costs` — "Subscription engines" panel now shows an estimated shadow-cost total alongside call count and token totals, so operators can compare a Copilot session against a pay-as-you-go spend on the same scale. `test_connection` rows are excluded from all roll-ups.

**`Services\ProviderTypeRegistry` + `Support\ProviderTypeDescriptor` — provider-type single source of truth**
- Bundled descriptors for all 9 shipped types (`builtin` / `anthropic` / `anthropic-proxy` / `bedrock` / `vertex` / `google-ai` / `openai` / `openai-compatible` / `kiro-api`) each carrying: label_key, desc_key, icon, form fields[], default_backend, allowed_backends[], needs_api_key, needs_base_url, env_key, base_url_env, env_extras (extra_config → env var map for bedrock / vertex / google-ai), backend_env_flags (static flags like `CLAUDE_CODE_USE_BEDROCK=1` that fire only when routed through a specific backend).
- API: `all()` / `get($type)` / `forBackend($backend)` / `requiresApiKey($type)` / `requiresBaseUrl($type)`. Registered as a singleton in `SuperAICoreServiceProvider::register()`.
- Host-config overlay: new `super-ai-core.provider_types` config key accepts partial overrides (e.g. re-point a type's `label_key` to a host-owned lang namespace) OR brand-new types the bundle doesn't know about (e.g. a future `xai-api`). Merge order: config > bundled.
- `AiProvider::typesForBackend()` / `requiresApiKey()` / `requiresBaseUrl()` now delegate to the registry when the container is booted. `BACKEND_TYPES` constant preserved as a fallback for pre-boot / CLI contexts.
- `ProviderTypeDescriptor::toArray()` returns the exact legacy shape SuperTeam's Blade templates already iterate (`label_key`, `desc_key`, `icon`, `fields`, `backend`, `allowed_backends`), so host-side migration is a controller swap, not a view rewrite.

**`Services\ProviderEnvBuilder` — centralized env injection**
- Replaces the hardcoded env-var switch that every `*CliBackend::buildEnv()` and every host runner (e.g. SuperTeam's `ClaudeRunner::providerEnvVars()`) used to duplicate. Reads the descriptor's `envKey` / `baseUrlEnv` / `envExtras` / `backendEnvFlags` and produces the `{VAR => value}` map for `Process::setEnv()`.
- `buildEnv(AiProvider $provider, ?string $apiKeyEnvKey = null)` — drives from a persisted provider row.
- `buildEnvFromConfig(array $providerConfig)` — drives from the `provider_config` array Dispatcher-driven backends pass around. `KiroCliBackend::buildEnv()` is the first internal consumer (dropped the local `KIRO_API_KEY` literal).
- Registered as a singleton in `SuperAICoreServiceProvider::register()`.

**`Services\KiroModelResolver` — live model catalog from `kiro-cli`**
- Kiro is the only CLI in the matrix that exposes its authoritative model list programmatically (`kiro-cli chat --list-models --format json-pretty`), so this resolver does NOT carry a hardcoded catalog. Three-layer resolution: in-process memo → `~/.cache/superaicore/kiro-models.json` (24h TTL) → live CLI probe → 12-row static fallback (only when the binary is missing).
- `catalog()` / `families()` / `defaultFor($family)` / `resolve($id)` shape mirrors `ClaudeModelResolver` / `CopilotModelResolver` so any picker iterating a resolver uniformly keeps working.
- `parseListModels($json)` is exposed for testing; the rest is static. `refresh()` bypasses the TTL for on-demand updates.
- Surfaces the **full** Kiro roster in the picker — previously 0.6.1 showed only 4 Anthropic IDs, now users see all 12: `auto`, `claude-{opus,sonnet}-4.{5,6}`, `claude-sonnet-4`, `claude-haiku-4.5`, `deepseek-3.2`, `minimax-m2.5`, `minimax-m2.1`, `glm-5`, `qwen3-coder-next`. New Kiro models appear in the dropdown as soon as the CLI knows about them — no `composer update` required.

### Changed

**Test-connection buttons now tag themselves**
- `Http\Controllers\ProviderController::testBuiltin()`, `ProviderController::test()`, and `Http\Controllers\AiServiceController::testService()` now pass `task_type => 'test_connection'` plus a short `capability` and `metadata.origin` to the Dispatcher. Rows from the "Test" buttons are grouped and hidden from the dashboards by default instead of cluttering them.

**Billing model stamped at write time, not recomputed on read**
- `CostDashboardController` now prefers the row-stamped `billing_model` (set by the Dispatcher at record time) and falls back to `CostCalculator::billingModel()` only for pre-0.6.1 rows. This keeps historical accuracy when the catalog changes.

**Kiro model picker / pricing seed switched to Kiro's dot-separated slug vocabulary**
- `EngineCatalog::seed('kiro').available_models` — 4 dash-format entries (`claude-sonnet-4-6`, …) replaced with 12 dot-format entries matching what `kiro-cli chat --list-models` actually returns.
- `EngineCatalog::seed('kiro').default_model` — `claude-sonnet-4-6` → `claude-sonnet-4.6`.
- `EngineCatalog::resolverOptions('kiro')` — was reusing `ClaudeModelResolver` (wrong vocab, limited scope); now reads `KiroModelResolver::catalog()` + `::families()`.
- `EngineCatalog::expandFromCatalog()` — dropped the `'kiro' => 'anthropic'` / `'kiro' => 'claude-'` branches. SuperAgent `ModelCatalog` speaks dash-format Anthropic IDs, which don't match Kiro's dot-format at all, so that expansion only ever added noise.
- `config/super-ai-core.php` pricing rows — the five `kiro:claude-*-4-X` dash-slug keys (which never matched a real CLI call because Kiro never accepted dash-format) are replaced with twelve correct dot-slug keys, each annotated with Kiro's own credit `rate_multiplier` for operator reference.

**`CliStatusDetector::detectAuth()` — generic fallback for catalog-registered CLI engines**
- Adds a default branch that walks `ProviderTypeRegistry` looking for any configured type whose `env_key` is set in the child env, and checks for a `~/.<binary>/` config directory. Returns `{loggedIn, status, method, expires_at}` in the same shape as the Claude / Codex / Gemini / Copilot branches.
- Closes the 0.6.1 cosmetic gap where the `/providers` Kiro card showed "installed ✓" but left the auth line blank. Also future-proofs any new CLI engine added via `EngineCatalog::seed()` or host config — the new card gets a sensible auth readout without a code change.

### Fixed

**Kiro CLI silently ignored the `--model` selection (0.6.1 regression)**
- `Backends\KiroCliBackend::generate()` was reading `$options['model']` into a local variable but never passing it to the spawned `kiro-cli` process — every call went through Kiro's `auto` router regardless of what the user picked on `/providers` or supplied to `$dispatcher->dispatch(['model' => …])`. Now the argv includes `--model <id>` when a model is supplied, with `KiroModelResolver::resolve()` translating Claude-style dash IDs (e.g. `claude-sonnet-4-6`) into the dot-format Kiro requires (`claude-sonnet-4.6`) before handing off.
- Docstring also corrected — 0.6.1's comment claimed "Model selection is NOT a CLI flag in headless mode", which contradicted Kiro 2.x's actual `kiro-cli chat --help` output.

### Migration notes

1. Run `php artisan migrate` on every host (adds two nullable columns — safe, non-destructive).
2. Existing rows get `NULL` for both new columns; the dashboards render `—` for them. Subscription rows previously written with `cost_usd=0` will only surface shadow cost going forward; backfill is not attempted.
3. Host apps that already wire their own runners and want real-execution tracking: call `app(UsageRecorder::class)->record()` from each CLI completion path. See the snippet above. No API breakage for hosts that do nothing.
4. Hosts that published `config/super-ai-core.php` and hand-edited the `kiro:*` pricing rows: the published file still carries the dash-slug keys from 0.6.1. Re-publish with `php artisan vendor:publish --tag=super-ai-core-config --force` (or hand-migrate: rename `kiro:claude-sonnet-4-6` → `kiro:claude-sonnet-4.6`, add the 7 new rows). The old dash-slug rows were never matched by any real CLI call and can be deleted safely.
5. If `kiro-cli` is installed on hosts but the Kiro dropdown looks stale, force a catalog refresh with `php -r 'SuperAICore\Services\KiroModelResolver::refresh();'` (or just delete `~/.cache/superaicore/kiro-models.json` — the next request will reprobe).

### Known gaps (follow-up)

- `Runner\ClaudeAgentRunner`, `CodexAgentRunner`, `GeminiAgentRunner`, `CopilotAgentRunner` still emit plain-text streams and do not auto-record usage — adopting stream-json output would change the user-visible output format, so opt-in wiring is deferred to 0.7. Hosts that want tracking today should adopt `UsageRecorder` at their own CLI call sites.
- No backfill of the handful of "Test connection" rows with `task_type=NULL` from before 0.6.1 — they'll disappear as soon as the "Hide 0-token rows" default filter is applied, but you can also clear them manually: `DELETE FROM ai_usage_logs WHERE task_type IS NULL AND input_tokens=0 AND output_tokens=0;`
- Claude / Codex / Gemini / Copilot CLIs do **not** expose a list-models subcommand (only Kiro does), so their pickers stay on the SuperAgent `ModelCatalog` fallback — already dynamic via `superaicore super-ai-core:models update`, but not live-probed on every invocation the way Kiro's is. Adding provider-API probing (Anthropic `/v1/models`, OpenAI `/v1/models`, Google `v1/models:list`) is deferred to 0.7.

### Tests

- 28 new tests, 103 new assertions across the release:
  - 10 for `KiroModelResolver` (JSON parse, dash→dot, family aliases, static fallback, malformed input)
  - 8 for `ProviderTypeRegistry` (bundled 9 types present, forBackend filter, kiro-api shape, bedrock env-extras / backend flag, host-config rebrand, host-config added type, legacy toArray shape)
  - 10 for `ProviderEnvBuilder` (each of the 9 bundled types produces expected env map, host-added type, missing api_key, unknown type)
- Full suite: **276 tests / 812 assertions / 0 failures / 0 skipped** (was 248 / 709 at 0.6.1). Live-probe smoke verified against `kiro-cli` 2.x on macOS: all 12 models round-trip, cache file written to `~/.cache/superaicore/kiro-models.json`, dash→dot resolver translations line up with Kiro's router accept list.

### Host-app migration (SuperTeam & similar)

Host apps that previously duplicated a `PROVIDER_TYPES` matrix, a `providerEnvVars()` switch, or a hardcoded backend→label table can now replace those with single-line registry queries. After the migration, any future API type SuperAICore adds surfaces in the host UI via `composer update` with no code change. Typical replacements:

```php
// BEFORE (host-side duplicated matrix)
const PROVIDER_TYPES = [
    AiProvider::TYPE_ANTHROPIC => ['backend' => …, 'icon' => …, 'fields' => …],
    AiProvider::TYPE_OPENAI    => ['backend' => …, 'icon' => …, 'fields' => …],
    // … grew every time SuperAICore added a type
];

// AFTER
$types = app(\SuperAICore\Services\ProviderTypeRegistry::class)->all();
// optionally filter: ->forBackend($backend)
// each entry is a ProviderTypeDescriptor; ->toArray() gives the legacy shape
```

```php
// BEFORE (host-side env switch)
switch ($provider->type) {
    case AiProvider::TYPE_ANTHROPIC:  $env['ANTHROPIC_API_KEY'] = $apiKey; break;
    case AiProvider::TYPE_BEDROCK:    $env['AWS_ACCESS_KEY_ID'] = …; …
    // … 7 cases, missing TYPE_KIRO_API
}

// AFTER
$env = app(\SuperAICore\Services\ProviderEnvBuilder::class)->buildEnv($provider);
```

Hosts can override individual descriptors via `config/super-ai-core.php`'s `provider_types` key — handy to point `label_key` at the host's own lang namespace without restating the rest of the descriptor.

---

## [0.6.1] — 2026-04-20

Adds **AWS Kiro CLI** (`kiro-cli` ≥ 2.0) as the sixth execution engine. Kiro joins the matrix with the richest out-of-the-box feature set of any CLI backend — native **agents**, **skills**, **MCP**, **subagent DAG orchestration**, **and** two auth channels (local `kiro-cli login` and `KIRO_API_KEY` headless mode). Subagents are native (no `SpawnPlan` emulation needed), skills read the Claude `SKILL.md` format verbatim, and MCP config lives at `~/.kiro/settings/mcp.json` with the same `mcpServers` schema plus Kiro-specific extensions (`disabled`, `autoApprove`, `disabledTools`, remote `url`/`headers`).

Subscription engine (Kiro Pro / Pro+ / Power credit plans), so costs route into the dashboard's subscription bucket the same way Copilot does — per-token USD stays at 0 and the CLI backend surfaces per-call `credits` + `duration_s` under `usage` for hosts that want to render credit dashboards.

All additive — no breaking changes. Existing installs that don't have `kiro-cli` on `$PATH` see it report as unavailable in `cli:status` / `list-backends` and continue to use the other five engines unchanged.

### Added

**Kiro CLI execution engine**
- `Backends\KiroCliBackend` — spawns `kiro-cli chat --no-interactive --trust-all-tools <prompt>`, parses the plain-text response body, and extracts the trailing `▸ Credits: X • Time: Y` summary line into `usage.credits` / `usage.duration_s`. Supports both auth channels: `type=builtin` leaves env untouched so the host's `kiro-cli login` keychain state carries the request, `type=kiro-api` injects the stored key as `KIRO_API_KEY` which makes `kiro-cli` skip its browser login flow.
- `Capabilities\KiroCapabilities` — `supportsSubAgents()=true` (Kiro's native DAG planner runs the orchestration; no `SpawnPlan` emulation needed), MCP path `~/.kiro/settings/mcp.json`, tool-name map for the lowercase Kiro vocabulary (`Read`→`read`, `Grep`→`grep`, `Bash`→`bash`, …). `renderMcpConfig()` writes the same `mcpServers` key Claude uses **plus** preserves `disabled` / `autoApprove` / `disabledTools` on entries the user added, and supports remote servers via `url` / `headers`.
- `Runner\KiroAgentRunner` — `kiro-cli chat --no-interactive --trust-all-tools --agent <name> <task>`. Auto-syncs the agent JSON before spawn.
- `Runner\KiroSkillRunner` — sends the SKILL.md body verbatim to `kiro-cli chat --no-interactive`. Kiro reads Claude's skill frontmatter shape natively, so no translator preamble is injected.
- `Sync\KiroAgentWriter` — translates `.claude/agents/*.md` → `~/.kiro/agents/<name>.json`. Field mapping: body→`prompt`, `model`→`model` (Anthropic slugs pass through unchanged), `allowed-tools` → lowercased `tools` + `allowedTools`. Reuses `AbstractManifestWriter` so user-edited JSONs are preserved (STATUS_USER_EDITED) and removed source agents are cleaned up (STATUS_REMOVED).
- `Console\Commands\KiroSyncCommand` — `kiro:sync [--dry-run] [--kiro-home <dir>]` prints the +/- change table and writes `~/.kiro/agents/<name>.json` files. Mostly a manual preview — `agent:run --backend=kiro` auto-syncs the targeted agent.
- Registered in `EngineCatalog::seed()` with `billing_model=subscription`, `cli_binary=kiro-cli`, `dispatcher_backends=['kiro_cli']`, and a `ProcessSpec` that pins the `chat --no-interactive --trust-all-tools` prefix so the default `CliProcessBuilderRegistry` builder produces the right argv. Wired into `BackendRegistry`, `CapabilityRegistry`, `BackendState::DISPATCHER_TO_ENGINE`, `McpManager::syncAllBackends()`, and the `AgentRunCommand` / `SkillRunCommand` runner factories.

**Kiro provider type (`kiro-api`)**
- `Models\AiProvider::TYPE_KIRO_API` + `BACKEND_KIRO` constants; `BACKEND_TYPES[kiro] = [builtin, kiro-api]`. `requiresApiKey()` treats `kiro-api` like `openai` / `anthropic` so the provider form prompts for a key. `TYPE_BUILTIN` remains the "host has already run `kiro-cli login`" path with no env injection.

**Kiro model picker flows through ModelCatalog**
- `EngineCatalog::expandFromCatalog()` maps `kiro → anthropic` with a `claude-` prefix filter, so the same SuperAgent `ModelCatalog` refresh that updates Claude / Codex / Gemini also surfaces new Anthropic model IDs in the Kiro dropdown.
- `EngineCatalog::resolverOptions('kiro')` reuses `ClaudeModelResolver::families()` + `::catalog()` for identical slugs (family aliases `sonnet` / `opus` / `haiku` ship alongside full IDs) and appends Kiro's routing primitive `auto` ("Auto (Kiro router picks the cheapest model)").

**MCP sync reaches the sixth engine**
- `McpManager::syncAllBackends()` picks up `kiro` automatically through the `EngineCatalog::keys()` → `supportsMcp()` filter; the hardcoded fallback list (used only when the container isn't booted) adds `kiro` for parity.

**Pricing entries**
- `config/super-ai-core.php` — five `kiro:<model>` subscription rows (`claude-sonnet-4-6`, `claude-sonnet-4-5`, `claude-opus-4-6`, `claude-haiku-4-5`, `auto`) with `input=0 / output=0 / billing_model=subscription`. Core cost totals stay at $0 per-call; host apps that want a credit dashboard read `usage.credits` off the dispatcher response.

### Changed

- `AgentRunCommand` / `SkillRunCommand` — `--backend` option docstring now lists `claude|codex|gemini|copilot|kiro|superagent`. Runner factory gains a `kiro` branch for both commands.
- `BackendRegistry` — new `kiro_cli` config section (binary / timeout / trust-all-tools); defaults to enabled so fresh installs without `kiro-cli` on `$PATH` see `isAvailable()=false` and skip the engine.
- `Console\Application` registers `kiro:sync` alongside `gemini:sync` / `copilot:sync` / `copilot:sync-hooks`.

### Tests

- 5 new tests: 4 × `KiroCliBackend::parseOutput()` (UTF-8 `▸` bullet, ASCII `>` fallback, missing summary line, empty input), 1 × `EngineCatalog::modelOptions('kiro')` (Claude resolver reuse + `auto` pseudo-model).
- Harness updates: `BackendRegistryTest` config fixtures include `kiro_cli` in both the "register all" and "disable all except anthropic_api" scenarios.
- Full suite: **248 tests / 709 assertions / 0 failures / 0 skipped** (was 243 / 690 at 0.6.0).

### Environment reference

```env
# Kiro CLI backend (0.6.1+) — disable if you don't want superaicore to
# probe for the binary at all. All defaults are safe; leaving untouched is
# fine when kiro-cli isn't installed.
AI_CORE_KIRO_CLI_ENABLED=true
KIRO_CLI_BIN=kiro-cli
# Kiro's --no-interactive mode refuses to run tools without prior per-tool
# approval unless this is on. Flip false only for workflows that
# pre-populate approvals via `--trust-tools=<categories>`.
AI_CORE_KIRO_TRUST_ALL_TOOLS=true

# Kiro API-key auth (headless, Pro / Pro+ / Power subscribers). Setting
# KIRO_API_KEY makes kiro-cli skip its browser login flow. Stored per
# provider in the DB via type=kiro-api; this env var is only needed when
# the CLI is invoked outside superaicore's dispatcher.
# KIRO_API_KEY=ksk_...
```

```bash
# Drive Kiro from the CLI
./vendor/bin/superaicore call "Hello" --backend=kiro_cli
./vendor/bin/superaicore agent:run reviewer "audit this diff" --backend=kiro
./vendor/bin/superaicore skill:run simplify --backend=kiro --exec=native

# Preview agent JSON that would land in ~/.kiro/agents/
./vendor/bin/superaicore kiro:sync --dry-run
```

---

## [0.6.0] — 2026-04-19

Minor-version bump because the **SuperAgent `ModelCatalog` (0.8.7)** now flows into every place SuperAICore used to hand-maintain model metadata: `CostCalculator` pricing, `ModelResolver` alias lookup, `EngineCatalog::modelOptions()` dropdown bodies, and the new `super-ai-core:models` CLI. Host apps running `superagent models update` immediately see updated pricing and new model rows without a `composer update` or `vendor:publish`. Also: Gemini CLI OAuth state lands on the `/providers` card, the model-picker placeholder is translated for en/zh-CN/fr, and `CliStatusDetector` picks up host-registered CLI engines automatically.

All additive — no breaking changes. Host apps that already publish `model_pricing` or `super-ai-core.engines.<key>.available_models` keep their authoritative values; the catalog fallback only fires when the host hasn't opined.

### Added

**SuperAgent `ModelCatalog` integrated as a pricing fallback**
- `Services\CostCalculator::resolveRate()` — new 4th step after config lookup + longest-prefix match falls through to `\SuperAgent\Providers\ModelCatalog::pricing($model)`. The bundled SuperAgent catalog covers every current Anthropic / OpenAI / Gemini / OpenRouter / Bedrock row, including entries SuperAICore's `model_pricing` config didn't enumerate (`claude-opus-4-6-20250514`, `claude-sonnet-4-7`, `gpt-5-nano`, `gemini-1.5-*`, etc.). Config still wins when set — defence-in-depth for hosts that publish their own rates.
- `Services\ClaudeModelResolver::resolve()` / `Services\GeminiModelResolver::resolve()` — after the local `FAMILIES` / `ALIASES` table misses, consult `ModelCatalog::resolveAlias()` with a provider-prefix guard (`claude-` / `gemini`) so Gemini's resolver can never return a Claude id and vice versa. Adds aliases like `gemini` → `gemini-2.0-flash`, `claude-opus` → latest Opus without editing the resolver.
- `Services\EngineCatalog` — seed's `available_models` is now unioned with `ModelCatalog::modelsFor(<provider>)` entries for claude / gemini / codex. Seed order is preserved; catalog-only ids get appended. Copilot stays on its dot-ID list; hosts that publish `super-ai-core.engines.<key>.available_models` override the union entirely.

**`super-ai-core:models` CLI (`Console\Commands\ModelsCommand.php`)**
- `list [--provider <p>]` — prints the merged (bundled + user override) catalog with per-1M pricing and aliases.
- `update [--url <u>]` — fetches the remote catalog to `~/.superagent/models.json` atomically. Honours `SUPERAGENT_MODELS_URL` by default.
- `status` — shows source provenance + override mtime + staleness + total rows loaded.
- `reset [-y]` — deletes the user override with a confirmation prompt (skip via `-y`).
- Exposed via the standalone `bin/superaicore` entry point. Registered in `Console\Application` alongside `cli:status` / `cli:install`.

**Opt-in catalog auto-refresh at CLI startup**
- `bin/superaicore` — invokes `ModelCatalog::maybeAutoUpdate()` before constructing the application. No-op unless `SUPERAGENT_MODELS_AUTO_UPDATE=1` AND `SUPERAGENT_MODELS_URL` is set AND the user override is older than 7 days. Network failures are swallowed so a dead remote never blocks the CLI.

**Gemini CLI OAuth detection**
- `Services\CliStatusDetector::detectAuth('gemini', ...)` — new branch reads `~/.gemini/oauth_creds.json` / `credentials.json` / `settings.json` via `\SuperAgent\Auth\GeminiCliCredentials`, falls back to `GEMINI_API_KEY` / `GOOGLE_API_KEY` env vars, and reports `{loggedIn, status, method, expires_at}` the same shape the claude/codex branches return. The `/providers` Gemini card now shows "logged in (oauth)" instead of "?" when the user ran `gemini login`.

### Changed

**Model-picker placeholder is translated**
- `Services\EngineCatalog::modelOptions()` — signature changed from `string $placeholder = '— 继承默认 —'` to `?string $placeholder = null`. When null (default) the method pulls `trans('super-ai-core::messages.inherit_default')`, falling back to the English literal `(inherit default)` when no Laravel translator is registered (e.g. plain PHPUnit). en/zh-CN/fr message files already carried the key; the hardcoded CN literal was the only blocker for EN/FR UIs.

**`CliStatusDetector` picks up host-registered CLI engines**
- `all()` iterates `EngineCatalog::keys()` instead of a hardcoded list, so any engine a host app registered via `super-ai-core.engines` config with `is_cli: true` + `cli_binary: <name>` surfaces in `cli:status` and the `/providers` cards. Built-in engines still hit `detectBinary()` directly for a fast path; catalog engines are resolved through the registered descriptor.
- `detect(<backend>)` accepts any backend key that the catalog knows; unknown backends fall through to a `['installed' => false]` stub instead of silently being dropped.

**`BackendRegistry` constructor accepts a testable SDK-availability callable**
- New optional third param `?callable $superagentAvailable = null` lets tests inject `fn() => false` to exercise the "SuperAgent SDK absent" branch without having to uninstall the package. Defaults to `[SuperAgentDetector::class, 'isAvailable']` so production callers see no behaviour change.

### Fixed

**Previously-unreachable SDK-missing test now runs**
- `tests\Unit\BackendRegistryTest::test_superagent_is_hidden_when_sdk_missing_even_with_config_enabled` used to call `markTestSkipped()` on every run because `composer.json` requires `forgeomni/superagent` as a hard dep — `class_exists(\SuperAgent\Agent::class)` is always true. The test now uses the injectable availability callable, asserts the negative path, and a matching `test_superagent_registered_when_sdk_available_and_enabled` covers the positive path. Skip count drops from 1 to 0.

### Tests
- 18 new tests: 3 × `CostCalculator` (catalog fallback, config-wins, no-match-returns-zero), 2 × `GeminiModelResolver` (catalog alias resolution, cross-provider isolation), 4 × `ModelsCommand` (list / filter / status / unknown-action), 3 × `CliStatusDetectorGeminiAuth` (oauth file / env key / not-logged-in), 5 × `EngineCatalog` (placeholder fallback, explicit placeholder, claude + gemini catalog expansion, host override wins, copilot untainted), 1 × `BackendRegistry` (SDK-present positive path). The pre-existing `test_superagent_is_hidden_when_sdk_missing...` case now actually executes.
- Full suite: **243 tests / 690 assertions / 0 failures / 0 skipped** (was 225 / 634 / 1 skipped at 0.5.9).

### Environment reference

```env
# Opt-in catalog auto-refresh at CLI startup (both must be set)
SUPERAGENT_MODELS_URL=https://your-cdn/models.json
SUPERAGENT_MODELS_AUTO_UPDATE=1
```

```bash
# Inspect or refresh the model catalog
./vendor/bin/superaicore super-ai-core:models status
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic
./vendor/bin/superaicore super-ai-core:models update                 # from $SUPERAGENT_MODELS_URL
./vendor/bin/superaicore super-ai-core:models update --url https://…
./vendor/bin/superaicore super-ai-core:models reset                  # delete user override
```
t## [0.6.1] — 2026-04-20

Adds **AWS Kiro CLI** (`kiro-cli` ≥ 2.0) as the sixth execution engine. Kiro joins the matrix with the richest out-of-the-box feature set of any CLI backend — native **agents**, **skills**, **MCP**, **subagent DAG orchestration**, **and** two auth channels (local `kiro-cli login` and `KIRO_API_KEY` headless mode). Subagents are native (no `SpawnPlan` emulation needed), skills read the Claude `SKILL.md` format verbatim, and MCP config lives at `~/.kiro/settings/mcp.json` with the same `mcpServers` schema plus Kiro-specific extensions (`disabled`, `autoApprove`, `disabledTools`, remote `url`/`headers`).

Subscription engine (Kiro Pro / Pro+ / Power credit plans), so costs route into the dashboard's subscription bucket the same way Copilot does — per-token USD stays at 0 and the CLI backend surfaces per-call `credits` + `duration_s` under `usage` for hosts that want to render credit dashboards.

All additive — no breaking changes. Existing installs that don't have `kiro-cli` on `$PATH` see it report as unavailable in `cli:status` / `list-backends` and continue to use the other five engines unchanged.

### Added

**Kiro CLI execution engine**
- `Backends\KiroCliBackend` — spawns `kiro-cli chat --no-interactive --trust-all-tools <prompt>`, parses the plain-text response body, and extracts the trailing `▸ Credits: X • Time: Y` summary line into `usage.credits` / `usage.duration_s`. Supports both auth channels: `type=builtin` leaves env untouched so the host's `kiro-cli login` keychain state carries the request, `type=kiro-api` injects the stored key as `KIRO_API_KEY` which makes `kiro-cli` skip its browser login flow.
- `Capabilities\KiroCapabilities` — `supportsSubAgents()=true` (Kiro's native DAG planner runs the orchestration; no `SpawnPlan` emulation needed), MCP path `~/.kiro/settings/mcp.json`, tool-name map for the lowercase Kiro vocabulary (`Read`→`read`, `Grep`→`grep`, `Bash`→`bash`, …). `renderMcpConfig()` writes the same `mcpServers` key Claude uses **plus** preserves `disabled` / `autoApprove` / `disabledTools` on entries the user added, and supports remote servers via `url` / `headers`.
- `Runner\KiroAgentRunner` — `kiro-cli chat --no-interactive --trust-all-tools --agent <name> <task>`. Auto-syncs the agent JSON before spawn.
- `Runner\KiroSkillRunner` — sends the SKILL.md body verbatim to `kiro-cli chat --no-interactive`. Kiro reads Claude's skill frontmatter shape natively, so no translator preamble is injected.
- `Sync\KiroAgentWriter` — translates `.claude/agents/*.md` → `~/.kiro/agents/<name>.json`. Field mapping: body→`prompt`, `model`→`model` (Anthropic slugs pass through unchanged), `allowed-tools` → lowercased `tools` + `allowedTools`. Reuses `AbstractManifestWriter` so user-edited JSONs are preserved (STATUS_USER_EDITED) and removed source agents are cleaned up (STATUS_REMOVED).
- `Console\Commands\KiroSyncCommand` — `kiro:sync [--dry-run] [--kiro-home <dir>]` prints the +/- change table and writes `~/.kiro/agents/<name>.json` files. Mostly a manual preview — `agent:run --backend=kiro` auto-syncs the targeted agent.
- Registered in `EngineCatalog::seed()` with `billing_model=subscription`, `cli_binary=kiro-cli`, `dispatcher_backends=['kiro_cli']`, and a `ProcessSpec` that pins the `chat --no-interactive --trust-all-tools` prefix so the default `CliProcessBuilderRegistry` builder produces the right argv. Wired into `BackendRegistry`, `CapabilityRegistry`, `BackendState::DISPATCHER_TO_ENGINE`, `McpManager::syncAllBackends()`, and the `AgentRunCommand` / `SkillRunCommand` runner factories.

**Kiro provider type (`kiro-api`)**
- `Models\AiProvider::TYPE_KIRO_API` + `BACKEND_KIRO` constants; `BACKEND_TYPES[kiro] = [builtin, kiro-api]`. `requiresApiKey()` treats `kiro-api` like `openai` / `anthropic` so the provider form prompts for a key. `TYPE_BUILTIN` remains the "host has already run `kiro-cli login`" path with no env injection.

**Kiro model picker flows through ModelCatalog**
- `EngineCatalog::expandFromCatalog()` maps `kiro → anthropic` with a `claude-` prefix filter, so the same SuperAgent `ModelCatalog` refresh that updates Claude / Codex / Gemini also surfaces new Anthropic model IDs in the Kiro dropdown.
- `EngineCatalog::resolverOptions('kiro')` reuses `ClaudeModelResolver::families()` + `::catalog()` for identical slugs (family aliases `sonnet` / `opus` / `haiku` ship alongside full IDs) and appends Kiro's routing primitive `auto` ("Auto (Kiro router picks the cheapest model)").

**MCP sync reaches the sixth engine**
- `McpManager::syncAllBackends()` picks up `kiro` automatically through the `EngineCatalog::keys()` → `supportsMcp()` filter; the hardcoded fallback list (used only when the container isn't booted) adds `kiro` for parity.

**Pricing entries**
- `config/super-ai-core.php` — five `kiro:<model>` subscription rows (`claude-sonnet-4-6`, `claude-sonnet-4-5`, `claude-opus-4-6`, `claude-haiku-4-5`, `auto`) with `input=0 / output=0 / billing_model=subscription`. Core cost totals stay at $0 per-call; host apps that want a credit dashboard read `usage.credits` off the dispatcher response.

### Changed

- `AgentRunCommand` / `SkillRunCommand` — `--backend` option docstring now lists `claude|codex|gemini|copilot|kiro|superagent`. Runner factory gains a `kiro` branch for both commands.
- `BackendRegistry` — new `kiro_cli` config section (binary / timeout / trust-all-tools); defaults to enabled so fresh installs without `kiro-cli` on `$PATH` see `isAvailable()=false` and skip the engine.
- `Console\Application` registers `kiro:sync` alongside `gemini:sync` / `copilot:sync` / `copilot:sync-hooks`.

### Tests

- 5 new tests: 4 × `KiroCliBackend::parseOutput()` (UTF-8 `▸` bullet, ASCII `>` fallback, missing summary line, empty input), 1 × `EngineCatalog::modelOptions('kiro')` (Claude resolver reuse + `auto` pseudo-model).
- Harness updates: `BackendRegistryTest` config fixtures include `kiro_cli` in both the "register all" and "disable all except anthropic_api" scenarios.
- Full suite: **248 tests / 709 assertions / 0 failures / 0 skipped** (was 243 / 690 at 0.6.0).

### Environment reference

```env
# Kiro CLI backend (0.6.1+) — disable if you don't want superaicore to
# probe for the binary at all. All defaults are safe; leaving untouched is
# fine when kiro-cli isn't installed.
AI_CORE_KIRO_CLI_ENABLED=true
KIRO_CLI_BIN=kiro-cli
# Kiro's --no-interactive mode refuses to run tools without prior per-tool
# approval unless this is on. Flip false only for workflows that
# pre-populate approvals via `--trust-tools=<categories>`.
AI_CORE_KIRO_TRUST_ALL_TOOLS=true

# Kiro API-key auth (headless, Pro / Pro+ / Power subscribers). Setting
# KIRO_API_KEY makes kiro-cli skip its browser login flow. Stored per
# provider in the DB via type=kiro-api; this env var is only needed when
# the CLI is invoked outside superaicore's dispatcher.
# KIRO_API_KEY=ksk_...
```

```bash
# Drive Kiro from the CLI
./vendor/bin/superaicore call "Hello" --backend=kiro_cli
./vendor/bin/superaicore agent:run reviewer "audit this diff" --backend=kiro
./vendor/bin/superaicore skill:run simplify --backend=kiro --exec=native

# Preview agent JSON that would land in ~/.kiro/agents/
./vendor/bin/superaicore kiro:sync --dry-run
```


## [0.6.0] — 2026-04-19

Minor-version bump because the **SuperAgent `ModelCatalog` (0.8.7)** now flows into every place SuperAICore used to hand-maintain model metadata: `CostCalculator` pricing, `ModelResolver` alias lookup, `EngineCatalog::modelOptions()` dropdown bodies, and the new `super-ai-core:models` CLI. Host apps running `superagent models update` immediately see updated pricing and new model rows without a `composer update` or `vendor:publish`. Also: Gemini CLI OAuth state lands on the `/providers` card, the model-picker placeholder is translated for en/zh-CN/fr, and `CliStatusDetector` picks up host-registered CLI engines automatically.

All additive — no breaking changes. Host apps that already publish `model_pricing` or `super-ai-core.engines.<key>.available_models` keep their authoritative values; the catalog fallback only fires when the host hasn't opined.

### Added

**SuperAgent `ModelCatalog` integrated as a pricing fallback**
- `Services\CostCalculator::resolveRate()` — new 4th step after config lookup + longest-prefix match falls through to `\SuperAgent\Providers\ModelCatalog::pricing($model)`. The bundled SuperAgent catalog covers every current Anthropic / OpenAI / Gemini / OpenRouter / Bedrock row, including entries SuperAICore's `model_pricing` config didn't enumerate (`claude-opus-4-6-20250514`, `claude-sonnet-4-7`, `gpt-5-nano`, `gemini-1.5-*`, etc.). Config still wins when set — defence-in-depth for hosts that publish their own rates.
- `Services\ClaudeModelResolver::resolve()` / `Services\GeminiModelResolver::resolve()` — after the local `FAMILIES` / `ALIASES` table misses, consult `ModelCatalog::resolveAlias()` with a provider-prefix guard (`claude-` / `gemini`) so Gemini's resolver can never return a Claude id and vice versa. Adds aliases like `gemini` → `gemini-2.0-flash`, `claude-opus` → latest Opus without editing the resolver.
- `Services\EngineCatalog` — seed's `available_models` is now unioned with `ModelCatalog::modelsFor(<provider>)` entries for claude / gemini / codex. Seed order is preserved; catalog-only ids get appended. Copilot stays on its dot-ID list; hosts that publish `super-ai-core.engines.<key>.available_models` override the union entirely.

**`super-ai-core:models` CLI (`Console\Commands\ModelsCommand.php`)**
- `list [--provider <p>]` — prints the merged (bundled + user override) catalog with per-1M pricing and aliases.
- `update [--url <u>]` — fetches the remote catalog to `~/.superagent/models.json` atomically. Honours `SUPERAGENT_MODELS_URL` by default.
- `status` — shows source provenance + override mtime + staleness + total rows loaded.
- `reset [-y]` — deletes the user override with a confirmation prompt (skip via `-y`).
- Exposed via the standalone `bin/superaicore` entry point. Registered in `Console\Application` alongside `cli:status` / `cli:install`.

**Opt-in catalog auto-refresh at CLI startup**
- `bin/superaicore` — invokes `ModelCatalog::maybeAutoUpdate()` before constructing the application. No-op unless `SUPERAGENT_MODELS_AUTO_UPDATE=1` AND `SUPERAGENT_MODELS_URL` is set AND the user override is older than 7 days. Network failures are swallowed so a dead remote never blocks the CLI.

**Gemini CLI OAuth detection**
- `Services\CliStatusDetector::detectAuth('gemini', ...)` — new branch reads `~/.gemini/oauth_creds.json` / `credentials.json` / `settings.json` via `\SuperAgent\Auth\GeminiCliCredentials`, falls back to `GEMINI_API_KEY` / `GOOGLE_API_KEY` env vars, and reports `{loggedIn, status, method, expires_at}` the same shape the claude/codex branches return. The `/providers` Gemini card now shows "logged in (oauth)" instead of "?" when the user ran `gemini login`.

### Changed

**Model-picker placeholder is translated**
- `Services\EngineCatalog::modelOptions()` — signature changed from `string $placeholder = '— 继承默认 —'` to `?string $placeholder = null`. When null (default) the method pulls `trans('super-ai-core::messages.inherit_default')`, falling back to the English literal `(inherit default)` when no Laravel translator is registered (e.g. plain PHPUnit). en/zh-CN/fr message files already carried the key; the hardcoded CN literal was the only blocker for EN/FR UIs.

**`CliStatusDetector` picks up host-registered CLI engines**
- `all()` iterates `EngineCatalog::keys()` instead of a hardcoded list, so any engine a host app registered via `super-ai-core.engines` config with `is_cli: true` + `cli_binary: <name>` surfaces in `cli:status` and the `/providers` cards. Built-in engines still hit `detectBinary()` directly for a fast path; catalog engines are resolved through the registered descriptor.
- `detect(<backend>)` accepts any backend key that the catalog knows; unknown backends fall through to a `['installed' => false]` stub instead of silently being dropped.

**`BackendRegistry` constructor accepts a testable SDK-availability callable**
- New optional third param `?callable $superagentAvailable = null` lets tests inject `fn() => false` to exercise the "SuperAgent SDK absent" branch without having to uninstall the package. Defaults to `[SuperAgentDetector::class, 'isAvailable']` so production callers see no behaviour change.

### Fixed

**Previously-unreachable SDK-missing test now runs**
- `tests\Unit\BackendRegistryTest::test_superagent_is_hidden_when_sdk_missing_even_with_config_enabled` used to call `markTestSkipped()` on every run because `composer.json` requires `forgeomni/superagent` as a hard dep — `class_exists(\SuperAgent\Agent::class)` is always true. The test now uses the injectable availability callable, asserts the negative path, and a matching `test_superagent_registered_when_sdk_available_and_enabled` covers the positive path. Skip count drops from 1 to 0.

### Tests
- 18 new tests: 3 × `CostCalculator` (catalog fallback, config-wins, no-match-returns-zero), 2 × `GeminiModelResolver` (catalog alias resolution, cross-provider isolation), 4 × `ModelsCommand` (list / filter / status / unknown-action), 3 × `CliStatusDetectorGeminiAuth` (oauth file / env key / not-logged-in), 5 × `EngineCatalog` (placeholder fallback, explicit placeholder, claude + gemini catalog expansion, host override wins, copilot untainted), 1 × `BackendRegistry` (SDK-present positive path). The pre-existing `test_superagent_is_hidden_when_sdk_missing...` case now actually executes.
- Full suite: **243 tests / 690 assertions / 0 failures / 0 skipped** (was 225 / 634 / 1 skipped at 0.5.9).

### Environment reference

```env
# Opt-in catalog auto-refresh at CLI startup (both must be set)
SUPERAGENT_MODELS_URL=https://your-cdn/models.json
SUPERAGENT_MODELS_AUTO_UPDATE=1
```

```bash
# Inspect or refresh the model catalog
./vendor/bin/superaicore super-ai-core:models status
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic
./vendor/bin/superaicore super-ai-core:models update                 # from $SUPERAGENT_MODELS_URL
./vendor/bin/superaicore super-ai-core:models update --url https://…
./vendor/bin/superaicore super-ai-core:models reset                  # delete user override
```

## [0.5.9] — 2026-04-19

Follow-up release that closes two regressions shipped in 0.5.7/0.5.8 and finishes turning `EngineCatalog` into the single source of truth for host-app model pickers.

**Regressions fixed:**
1. The Copilot CLI card never appeared on `/providers` because the view filtered the CLI-status list through a hardcoded 4-engine map, silently dropping copilot.
2. CLI auth detection reported every CLI as "not signed in" under `php artisan serve` (and any FPM pool with `clear_env = yes`), because the request worker's env is stripped of `HOME`/`USER`/`LOGNAME` — `claude auth status`, `codex login status`, and the Copilot config-dir heuristic all need HOME to locate their credential stores.

**Catalog work:** model dropdowns that host apps used to hand-roll per backend (`if ($backend === 'claude') … elseif …`) now resolve from a single `EngineCatalog::modelOptions($key)` / `modelAliases($key)` call. New engines registered via `super-ai-core.engines` config light up in every host dropdown automatically.

### Fixed

**Providers page: Copilot card now renders**
- `resources/views/providers/index.blade.php` previously narrowed the CLI-status card list through `array_intersect_key` against a hardcoded `['claude', 'codex', 'gemini', 'superagent']` array, so the copilot engine — registered in the catalog since 0.5.7 — never produced a card. Rewrote to iterate the live `$engines` catalog so every enabled CLI surfaces automatically with its label, install status, version, path, auth state, and install hint. Added the `npm i -g @github/copilot` install hint for the "not installed" path.

**CLI auth detection survives env-stripped request workers**
- `Services\CliStatusDetector::childEnv()` — new helper that rebuilds the minimum env a CLI child needs (`HOME`, `USER`, `LOGNAME`, `PATH`, plus passthroughs for `TMPDIR`, `XDG_*`, `LANG`, and every documented CLI OAuth token env var) and hands it explicitly to every `Process::fromShellCommandline()` call. When `getenv('HOME')` is false (PHP's built-in dev server, FPM with `clear_env=yes`, supervisor configs that scrub env), we fall back to `posix_getpwuid(posix_getuid())` — the kernel knows the real user regardless of what PHP's env table says.
- `detectBinary()`, `detectAuth()` (claude/codex/copilot branches), `probeCopilotLive()`, and `findPath()` (both `node -v` + `which <binary>`) all now use the rebuilt env. Fixes the symptom where claude/codex/copilot cards showed "未登录" / "Not signed in" on `/providers` after a fresh `php artisan serve` even though all three CLIs were authenticated.

### Changed

**`EngineCatalog` is now the single source of truth for model dropdowns**
- `modelOptions(string $key, bool $withPlaceholder = true, string $placeholder = '— 继承默认 —'): array` — returns the associative shape `['' => placeholder, '<id>' => '<display>', ...]` that Blade `<select>` lists consume directly. Per-engine `ModelResolver` (Claude / Codex / Gemini / Copilot / SuperAgent) drives the body when present, so family aliases (`sonnet`, `pro`, `flash`) appear alongside the full catalog in one pass. Engines without a dedicated resolver (host-registered CLIs) fall back to `EngineDescriptor::availableModels`.
- `modelAliases(string $key): array` — same data reshaped as a sequential `[{id, name}, ...]` list, matching the JSON envelope task create/show blades' model-picker JS already expects.
- Host apps previously hand-maintained per-backend `switch ($backend)` statements in 3–4 controllers to build the same lists. They can now delete those and call the catalog. New engines plugged in via `super-ai-core.engines` config then auto-populate every host dropdown without host-side code changes.

**New `CopilotModelResolver`**
- `Services\CopilotModelResolver` — canonical model catalog for the Copilot CLI. Copilot's IDs use **dot** separators (`claude-sonnet-4.6`, `gpt-5.1`) — unlike Claude CLI's **dashes** (`claude-sonnet-4-6`). Before this resolver, hosts that piped a Claude-shaped ID through the copilot backend would get silently rejected ("Model '...' from --model flag is not available", exit 1, no assistant output). `resolve()` rewrites known family aliases (`sonnet`/`opus`/`haiku` → latest Copilot dot-ID) and passes unknown input through; `catalog()` / `families()` / `defaultFor()` mirror the shape the other resolvers already expose so `EngineCatalog::modelOptions('copilot')` gets family aliases + full catalog for free. The seeded `copilot` `available_models` list is rebuilt as a projection of this resolver so dashboard/legacy callers stay in sync.

**Copilot engine label tightened**
- `EngineCatalog::seed()` — `label` field for `copilot` changed from `'GitHub Copilot CLI'` to `'GitHub Copilot'`. Shows up on `/providers` card headers and in every `Built-in (<label>)` string that reads `$engine->label`. Docs and READMEs still refer to "GitHub Copilot CLI" in contexts where the CLI tool itself is being described.

### Tests
- 6 new `EngineCatalogTest` cases — resolver-driven options for claude + copilot (family aliases + full catalog), host-registered engine options (descriptor fallback), placeholder on/off, `modelAliases()` shape, unknown-engine guard.
- Full suite: 225 tests / 634 assertions / 1 pre-existing skip, zero regressions.

## [0.5.8] — 2026-04-18

Follow-up release on top of 0.5.7. Declarative CLI command-shape lands on `EngineDescriptor` so host apps stop duplicating process-launch tables, a builder registry derives argv from that spec with a per-engine override hatch, the Copilot auth heuristic gets an opt-in liveness probe, and the Gemini/Copilot sync writers share a single non-destructive skeleton. All additive — no breaking changes.

### Added

**Engine process-spec + CLI builder registry**
- `Support\ProcessSpec` — declarative command-shape metadata (binary, version args, auth-status args, prompt/output/model flags, default flags, default timeout). Host apps previously duplicated this table; it now lives on the engine catalog.
- `Support\EngineDescriptor` gains a nullable `processSpec` field, surfaced in `toArray()` and seeded for every CLI engine (claude/codex/gemini/copilot). `superagent` stays null. Hosts can override per-engine via `super-ai-core.engines.<key>.process_spec` (accepts `ProcessSpec` instance or array).
- `Services\CliProcessBuilderRegistry` — assembles argv arrays from a ProcessSpec (`build($key, ['prompt' => ..., 'model' => ...])`). Default builder covers all seeded engines; hosts call `register($key, $callable)` to override without forking. Also exposes `versionCommand()` / `authStatusCommand()` for the status detector path. Registered as a singleton on the service provider.

**Copilot CLI liveness probe (opt-in)**
- `Services\CliStatusDetector::detectAuth('copilot', ...)` now optionally verifies the binary itself runs (`copilot --help` under 3s timeout) and returns the result as `auth.live`. Gated behind `SUPERAICORE_COPILOT_PROBE=1` so status pages stay fast by default; result cached per-path within a request. `static::` dispatch lets hosts/tests subclass and swap the probe.

### Changed

**Sync writers share a single non-destructive skeleton**
- New `Sync\AbstractManifestWriter` hoists the contract that both `GeminiCommandWriter` and `CopilotAgentWriter` were implementing by hand: on-disk hash compare, user-edit detection, manifest round-trips, dry-run, stale cleanup, status constants. Concrete writers now only render targets and delegate to `applyTargets()` / `applyOne()`. `CopilotHookWriter` stays standalone — its single-JSON-file contract is too different to share.

**CLI installer — one-shot bootstrap for engine CLIs**
- `cli:status` — table of installed / version / auth / install-hint per backend (`claude` / `codex` / `gemini` / `copilot` / `superagent`). Pass `--json` for machine-readable output.
- `cli:install [backend?] [--all-missing] [--via=npm|brew|script] [--yes] [--dry-run]` — shells out to `npm`/`brew`/`curl|sh`. Default source is `npm` for uniformity (Windows/Linux/macOS); `brew` is offered for codex, `curl` for claude. Superagent is intentionally skipped (PHP SDK, not a CLI). Pre-flight check that the underlying tool (`npm` / `brew` / `sh`) resolves on PATH; exits 127 with a hint when it doesn't. Confirmation prompt by default; `--yes` skips it for CI.
- `Services\CliInstaller` — the install-command matrix + execution engine, reusable from host apps.

**Copilot fan-out + hooks sync (followups #3, #4)**
- `copilot:fleet <task> --agents a,b,c` — parallel Copilot sub-agent fan-out. Native `/fleet` is interactive-only, so we orchestrate N concurrent `copilot --agent X -p ... --output-format=json` children, stream their output with `[<agent>]` prefixes, aggregate per-agent `{text, model, output_tokens, premium_requests, exit_code}` via the existing JSONL parser, and register each child in `ai_processes`.
- `copilot:sync-hooks` — merge a host app's Claude-style `hooks` block (`.claude/settings.json.hooks` by default, configurable via `--source`) into `~/.copilot/config.json`. Copilot accepts PascalCase event names (`PreToolUse`/`PostToolUse`/`SessionStart`) verbatim and delivers the VS-Code-compatible snake_case payload, so translation is a pure file-placement operation. Manifest-tracked; re-sync is a no-op; user-edited hook blocks are detected via deep-ksort hashing and refused to overwrite.

### Changed

**CLI backends now report real token usage (followup #1)**
- `Backends\ClaudeCliBackend` — switched from `--print` (text) to `--output-format=json`. New `parseJson()` extracts `result` as text, `usage.{input_tokens,output_tokens,cache_read_input_tokens,cache_creation_input_tokens}`, `total_cost_usd`, and the primary model from `modelUsage` (picks the key with highest `costUSD` so side-call models like haiku don't overshadow the main opus answer).
- `Backends\CodexCliBackend` — switched to `exec --json`. New `parseJsonl()` parses the event stream: `item.completed{type=agent_message}` for text, `turn.completed.usage` for tokens, `turn.failed`/`error` for `stop_reason=error`.
- `Backends\GeminiCliBackend` — switched to `--output-format=json`. New `parseJson()` identifies the "main" answering model by `stats.models.<id>.roles.main` (falls back to highest-output when absent) and normalises Gemini-specific `candidates`/`prompt` token names to the canonical `input_tokens`/`output_tokens` contract.
- Dispatcher / CostCalculator downstream needed no changes — they already read `input_tokens` / `output_tokens`. Dashboards should stop showing `$0` for CLI routes that previously emitted placeholder usage.

**MonitoredProcess trait across all runners (followup #6)**
- New `Runner\Concerns\MonitoredProcess::runMonitored()` consolidates the `start()` → `ProcessRegistrar::start` → `wait()` with tee → `ProcessRegistrar::end` lifecycle. All 8 engine runners (Claude/Codex/Gemini/Copilot × Skill/Agent) now use it, so every CLI subprocess shows up in the Process Monitor UI with a live PID, log file, and finished/failed status. Copilot's two runners also migrated to the trait for consistency.
- `emit()` visibility on those runners widened from `private` to `protected` so the trait can call it.

### Tests
- 10 new `CliInstallerTest` cases (matrix coverage, source resolution, dry-run, unknown-backend, tool-available probe).
- 6 new `ClaudeCliBackendTest` + 6 new `CodexCliBackendTest` + 6 new `GeminiCliBackendTest` cases covering real JSONL/JSON envelopes, model-selection heuristics, failure paths, missing-field tolerance.
- 3 new `CopilotFleetRunnerTest` cases (dry-run fan-out + model override).
- 8 new `CopilotHookWriterTest` cases (written / unchanged / user-edited / cleared / hash stability / settings reader).
- 4 new `ProcessSpecTest` + 6 new `CliProcessBuilderRegistryTest` + 4 new `CliStatusDetectorCopilotProbeTest` cases covering seed shape, host overrides, default/override builders, positional-prompt CLIs, gated probe on/off + cache.
- Full suite: 219 tests / 617 assertions / 1 pre-existing skip, zero regressions.

## [0.5.7] — 2026-04-18

GitHub Copilot CLI lands as the fifth execution engine. Full end-to-end: backend, capabilities, skill/agent runners, `copilot:sync` for translating `.claude/agents` → `~/.copilot/agents/*.agent.md`, tool-permission translation from canonical Claude names to Copilot's category-glob syntax, and subscription-billing awareness on the cost dashboard. The `copilot` CLI itself handles OAuth device flow, keychain storage, and session-token refresh — we delegate entirely to the binary and never store GitHub credentials ourselves.

Also a focused set of infrastructure extractions used by Copilot and leveraged by the followups in `[Unreleased]`:

- `Services\EngineCatalog` — single source of truth for engine labels, icons, dispatcher backends, provider-type matrices, model catalogs, and process-scan keywords. New engines plug in via `EngineCatalog::seed()` and the UI / monitor / toggle-table update automatically. Host apps can override per-engine fields through `super-ai-core.engines` config.
- `Support\EngineDescriptor` — value object backing the catalog; also the contract the providers page iterates.
- `Support\ProcessRegistrar` — optional persistence helper that writes CLI subprocesses into `ai_processes` so the Process Monitor sees them. No-op outside Laravel (swallows throws when Eloquent isn't bound), which keeps the CLI runners framework-agnostic. Extended across all runners in `[Unreleased]` via a shared trait.
- `docs/copilot-followups.md` — written alongside this release to capture everything we deliberately did NOT ship in 0.5.7 (usage extraction for Claude/Codex/Gemini, `/fleet` fan-out, hooks integration, plugin-skill coverage, XDG path fix). Most of those are now landing in `[Unreleased]`.

### Added

**Copilot CLI backend**
- `Backends\CopilotCliBackend` — spawns `copilot -p <prompt> --allow-all-tools --output-format=json`. JSONL parser extracts assistant text (concatenated `assistant.message` events), the model the Copilot router actually selected (`session.tools_updated.data.model`), and output-token counts. Copilot doesn't report `input_tokens` (billing is request-based, not per-token), so that field stays 0 and the cost calculator's subscription-billing path handles the $0 USD contribution to dashboard totals. `premium_requests` (subscription metric) is exposed on the usage array but not consumed downstream yet.
- Auth delegated to the binary: `builtin` (local `copilot login`) is the default; `COPILOT_GITHUB_TOKEN` / `GH_TOKEN` / `GITHUB_TOKEN` passthrough for headless runners.
- New env flags: `AI_CORE_COPILOT_CLI_ENABLED`, `COPILOT_CLI_BIN`, `AI_CORE_COPILOT_ALLOW_ALL_TOOLS`.

**Copilot capabilities + tool-permission translation**
- `Capabilities\CopilotCapabilities` — no preamble (Copilot reads `.claude/skills/` natively), sub-agents supported (`--agent <name>`), MCP passthrough via `~/.copilot/mcp-config.json`. `toolNameMap()` returns empty because Copilot accepts canonical Claude names for most built-ins.
- `Translator\CopilotToolPermissions` — translates canonical Claude tool names in `allowed-tools:` / `disallowed-tools:` frontmatter to Copilot's `category(glob)` grant syntax (`Bash` → `shell`, `Read`/`Write`/`Edit` → `write`, etc.). Feeds `copilot --allow-tool` / `--deny-tool` repeatable flags.

**Copilot skill & agent runners**
- `Runner\CopilotSkillRunner` — `copilot -p <skill body + args> -s --allow-all-tools`. Zero-translation pass-through: Copilot reads `.claude/skills/` itself, so skill bodies referencing tool names resolve natively.
- `Runner\CopilotAgentRunner` — `copilot --agent <name> -p <task> -s --allow-all-tools`. Auto-syncs the `.agent.md` target before exec so users never need to remember `copilot:sync`. If the user has hand-edited the synced file, we proceed with a warning instead of overwriting.

**Copilot agent sync (Claude → Copilot agent file translation)**
- `Sync\CopilotAgentWriter` — reads `.claude/agents/<name>.md` and writes `~/.copilot/agents/<name>.agent.md` with a `# @generated-by: superaicore` + `# @source: <path>` header. Tracks per-target `sha256` in the manifest so we can detect user edits (refuse to overwrite) and stale files (left in place as `stale-kept`).
- `Console\Commands\CopilotSyncCommand` — `copilot:sync [--dry-run] [--copilot-home=...]`. Prints the same five-section change table as `gemini:sync` (`+written`, `·unchanged`, `-removed`, `!user-edited`, `!stale-kept`). `--copilot-home` defaults to `$XDG_CONFIG_HOME/copilot` when that's set, else `$HOME/.copilot`.

**Cost dashboard: subscription vs usage billing**
- `Services\CostCalculator::billingModel()` — reports `usage` or `subscription` per (model, backend). Copilot models are tagged `subscription` so they contribute $0 to the USD-per-call rollup but are counted separately in a new "Subscription calls" panel on the cost dashboard. Pricing catalog extended with Copilot's subscription-request tiers.
- `Http\Controllers\CostDashboardController` — splits the summary into `per-token` vs `subscription` rollups; views updated to match.

**Providers UI**
- Copilot card on `/providers` with install hint (`npm i -g @github/copilot`), `copilot login` reminder, and model catalog (gpt-5.4, claude-sonnet-4.6, etc.). Engine on/off toggle gates both `copilot_cli` at the dispatcher level.
- `BackendState::DISPATCHER_TO_ENGINE` gains `copilot_cli → copilot` mapping.

### Changed

- `Console\Application` adds `copilot:sync` and accepts `--backend=copilot` in `skill:run` / `agent:run`.
- `AgentRunCommand::inferBackend()` now recognises `gpt-5.*`-style Copilot model names alongside the existing family patterns. Still defaults to `claude` when in doubt.
- `ProcessMonitor::DEFAULT_KEYWORDS` includes `copilot` so the process monitor picks up Copilot CLI invocations.
- `CliStatusDetector` probes `copilot` on `$PATH`, reports version and a best-effort auth state (env token / local config / none).

### Tests
- `CopilotCliBackendTest` — 5 cases (JSONL parser happy path + multi-message concat + non-zero exit + empty / non-JSON input + bogus binary probe).
- `CopilotCapabilitiesTest` — 6 cases (capability flags + identity passthrough).
- `CopilotSkillRunnerTest` / `CopilotAgentRunnerTest` — dry-run shape, `--allow-all-tools` flag, allowed-tools note, auto-sync preamble.
- `CopilotAgentWriterTest` — 8 cases (first sync, idempotent second sync, stale cleanup, user-edited preservation, `--dry-run` isolation).
- `CopilotToolPermissionsTest` — canonical → category translation, mixed allow/deny, unmapped names.
- `EngineCatalogTest` — 11 cases covering label/icon lookup, backend → engine map, provider-type matrix, model catalog fallback, host override.
- `ProcessRegistrarTest` — 6 cases (null outside Laravel, unsafe pids rejected, log file creation, default path format).
- Full suite at release tag: 165 tests / 421 assertions / 1 skip.

## [0.5.6] — 2026-04-17

Absorbs the SuperRelay design as a thin skill-running CLI surface inside superaicore itself, instead of shipping a second package. Phase 1 (list + Claude exec), Phase 1.5 (translator + compatibility probe + codex/gemini runners, `--exec=native`), Phase 1.6 (fallback chain + side-effect lock, `--exec=fallback`), Phase 2 (sub-agent list + run), and Phase 3 (Gemini custom-command TOML sync) all land here.

Also fixes a 0.5.5 gap where `BackendCapabilities::transformPrompt()` existed but was never invoked — Gemini/Codex preambles are now actually prepended on every non-Claude skill/agent dispatch, so `skill run ... --backend=gemini` no longer falls back to `codebase_investigator` on external-research tasks.

In a second follow-up pass within this same version, we hardened the CLI surface so it isn't just a prompt pipe:

- `arguments:` frontmatter → typed CLI validation + structured `<arg name="..">` XML rendering into the skill body.
- `allowed-tools` frontmatter → passed through to `claude --allowedTools`; codex/gemini emit a `[note]` since neither CLI has an enforcement flag.
- Translator is now prose-safe: tool-name rewrites only fire in explicit shapes (backtick, `Name(`, "the X tool", "use/call/invoke X"). Bare capitalised words in prose are left alone; the preamble carries the translation hint for the model to interpret.

The standalone CLI binary is also renamed `super-ai-core` → `superaicore` to match the Composer package (`forgeomni/superaicore`); the Laravel package namespace (`super-ai-core::` views, `config/super-ai-core.php`, route prefix) is intentionally unchanged so existing hosts don't break.

### Added

**Skill registry — read `.claude/skills/*/SKILL.md`**
- `Registry\FrontmatterParser` — dependency-free YAML-frontmatter reader (~100 LOC). Handles scalars, quoted strings, single-level lists (block `- item` and flow `[a, b]`), `true`/`false`/`null` coercion, BOM + CRLF. Deliberately not a full YAML parser; avoids pulling in `symfony/yaml`.
- `Registry\Skill` — value object: `name`, `description`, `source` (`project`|`plugin`|`user`), `body`, `path`, `frontmatter`.
- `Registry\SkillRegistry` — three-source merge (project > plugin > user) with project winning on name collision. Sources map to `.claude/skills/` in cwd, `~/.claude/plugins/*/skills/`, `~/.claude/skills/`. Constructor takes injectable `cwd` + `home` for testability.

**Skill runners — pipe translated body through a backend CLI**
- `Runner\SkillRunner` interface (`runSkill(Skill, array $args, bool $dryRun): int`).
- `Runner\ClaudeSkillRunner` — `claude -p <body + <args> xml block>`, streams combined stdout/stderr via an injectable writer closure. Dry-run prints the resolved command shape.
- `Runner\CodexSkillRunner` — `codex exec --full-auto --skip-git-repo-check -` with prompt on stdin.
- `Runner\GeminiSkillRunner` — `gemini --prompt "" --yolo` with prompt on stdin (matches the invocation shape in `AgentSpawn/GeminiChildRunner`).

**Skill body translation + compatibility probe**
- `Translator\SkillBodyTranslator` — two-stage transform. Stage 1: rewrite canonical Claude tool names per the target `BackendCapabilities::toolNameMap()` using `\bToolName\b` word-boundaries (so `ReadMe` doesn't become `read_fileMe`). Empty-map backends skip stage 1 — the contract says empty map means canonical names are native, not "no mapping exists". Stage 2: call `BackendCapabilities::transformPrompt()` on the result. Gemini/Codex prepend their steering preambles (sub-agent Spawn Plan protocol, external-research guard, canonical→native tool hints); Claude/SuperAgent are identity. Preamble injection is idempotent via version-marker sentinels. Returns the rewritten body plus `translated` and `untranslated` arrays for reporting.
- `Runner\CompatibilityProbe` — static pre-flight returning `compatible` / `degraded` / `incompatible` + reasons. `Agent` on a backend without `supportsSubAgents()` is hard-incompatible. Backends with a non-empty toolNameMap (gemini) flag canonical tools missing from the map as `degraded`. Empty-map backends skip the gap check — we can't distinguish "native" from "missing" without a separate capability table (noted as a known limitation for codex's `WebSearch`).

**Fallback chain + side-effect hard-lock (DESIGN §5 D13–D16)**
- `Runner\SideEffectDetector` — best-effort probe for filesystem mutations produced by the run. Two signals: (a) cwd mtime snapshot taken before the run vs after (scoped; skips `.git`, `vendor`, `node_modules`, `.phpunit.cache`, `.idea`, `.claude`, `storage`, `bootstrap/cache`; capped at 10k files), and (b) regex scan of the raw output buffer for `"type":"tool_use"` events for mutating tools (`Write`, `Edit`, `Bash`, `NotebookEdit`, `write_file`, `replace`, `run_shell_command`, `apply_patch`). Reason list deduped and capped at 5 + overflow hint.
- `Runner\FallbackChain` — orchestrates the chain. For each hop: re-probe, skip on `incompatible` unless it's the last hop, translate the body, tee the runner's writer into a capture buffer, take a mtime snapshot, run, then diff. If side-effects detected → print `[fallback] locked on <backend>` with reasons and return the hop's exit code (D15 hard-lock — we do not roll to the next hop even if the run failed, to avoid double-writes). No side-effects + zero exit → return 0; no side-effects + non-zero exit → log and try the next hop (or propagate on last).
- `Console\Commands\SkillRunCommand` `--exec=fallback` + `--fallback-chain=a,b,c` — default chain resolves to `<backend>,claude` when `--backend` is not claude, else `[claude]`; chain is deduped. Dry-run mode short-circuits the detector (snapshot would otherwise scan the cwd).

**Argument schema (`arguments:` frontmatter)**
- `Registry\SkillArguments` — parses three recognised shapes: free-form string (single arg required), list of names (positional, all required, strict arity), map of name→description (named, all optional in v0). Validates caller-supplied positional args, returns a human error on missing-required / extra-positional. Renders into an `<args>` XML block (flat for free-form, `<arg name="...">` tagged for positional/named). Escapes XML specials so user-supplied URLs / HTML don't break the prompt. Richer v1 shapes (`- name: x, required: true`) require nested-YAML parsing that our minimal reader doesn't yet do; they degrade silently to "unknown schema" and the model sees the raw body.
- `Console\Commands\SkillRunCommand` parses the schema at dispatch time, validates, renders, and appends the block *after* translation — so prose in user-supplied args isn't touched by `SkillBodyTranslator`. Runners get `$args = []` because the block is already in the body.
- `Runner\FallbackChain::run()` takes a pre-rendered `string $renderedArgs` which is appended after per-hop translation.

**`allowed-tools` passthrough**
- `Registry\Skill` gains `allowedTools: string[]`, parsed from frontmatter (`allowed-tools` / `allowed_tools` / `tools`).
- `Runner\ClaudeSkillRunner` / `ClaudeAgentRunner` pass the list to the Claude CLI via `--allowedTools name1,name2,...`. Shows up in the dry-run line-out.
- `Runner\CodexSkillRunner` / `CodexAgentRunner` / `GeminiSkillRunner` / `GeminiAgentRunner` emit a single `[note]` line when `allowed-tools` is declared — neither CLI exposes a matching flag, so enforcement falls back to model obedience via the preamble.

**Translator hardening — prose-safe rewrite**
- `Translator\SkillBodyTranslator` now rewrites canonical tool names only when the shape disambiguates intent:
  - `` `Read` `` — backtick-wrapped identifier
  - `Read(...)` — function-call shape
  - "the Read tool" / "the `Read` tool"
  - "use/using/call/calling/invoke/invoking Read"
- Bare prose like "Read the config carefully and Write a one-line summary" is left alone; the backend preamble (stage 2) carries the translation hint for context-dependent references. Preamble injection is still idempotent via version-marker sentinels.
- `untranslated` gap detection stays at loose `\b` word-boundary — over-flagging a compatibility gap is safer than missing one.

**Gemini custom-command sync (DESIGN §7 Phase 3)**
- `Sync\Manifest` — reads/writes `<gemini-home>/commands/.superaicore-manifest.json`. Shape: `{version:1, generated_at, entries:{path:sha256}}`. Tracks what we wrote last time so we can (a) clean up stale TOMLs for skills/agents that disappeared, and (b) detect user edits to TOMLs we created and refuse to clobber them.
- `Sync\GeminiCommandWriter::sync(skills, agents)` — writes two TOML namespaces:
  - `<gemini-home>/commands/skill/<name>.toml` with `prompt = '!{superaicore skill:run <name> {{args}}}'`
  - `<gemini-home>/commands/agent/<name>.toml` with `prompt = '!{superaicore agent:run <name> "{{args}}"}'`
  - Each file carries a `# @generated-by: superaicore` + `# @source: <path>` header. Non-destructive contract per DESIGN §10 criterion 6: a TOML we wrote + since user-edited is preserved (reported as `user-edited`); a stale TOML the user modified is kept (reported as `stale-kept`); a user-deleted TOML is recreated on the next sync.
- `Console\Commands\GeminiSyncCommand` — `gemini:sync [--dry-run] [--gemini-home=...]`. Prints a five-section change table (`+written`, `·unchanged`, `-removed`, `!user-edited`, `!stale-kept`). `--gemini-home` override primarily exists for testability; defaults to `$HOME/.gemini`.

**Sub-agent registry + runners (DESIGN §7 Phase 2)**
- `Registry\Agent` — value object: `name`, `description`, `source` (`project`|`user`), `body` (system prompt), `path`, `model`, `allowedTools`, `frontmatter`.
- `Registry\AgentRegistry` — two-source merge per D7: `$cwd/.claude/agents/*.md` (project, wins) > `$home/.claude/agents/*.md` (user). Agents are flat `.md` files (not directories like skills). Frontmatter-missing `name` falls back to the filename stem. Reads optional `allowed-tools` / `allowed_tools` / `tools` lists, `model:` string.
- `Runner\AgentRunner` interface + `ClaudeAgentRunner` / `CodexAgentRunner` / `GeminiAgentRunner`. All three concatenate `body + "\n\n---\n\n" + task` and pipe to the respective CLI. `ClaudeAgentRunner` honors the `model:` frontmatter by resolving `opus`/`sonnet`/`haiku` aliases through `ClaudeModelResolver::resolve()` and passing `--model`. `CodexAgentRunner` passes `-m`; `GeminiAgentRunner` passes `--model`. Codex and Gemini runners also apply their capability's `transformPrompt()` to inject the backend preamble.
- `Console\Commands\AgentListCommand` — `agent:list [--format=table|json]`. Table columns: name, source, model, description.
- `Console\Commands\AgentRunCommand` — `agent:run <name> <task> [--backend=claude|codex|gemini] [--dry-run]`. When `--backend` is omitted, backend is inferred from the agent's `model:` (`claude-*`/family alias → claude, `gemini-*` → gemini, `gpt-*`/`o[1-9]-*` → codex, otherwise claude).

**Console commands**
- `Console\Commands\SkillListCommand` — `skill:list [--format=table|json]`. Table shows name, source, description (truncated to 80 chars).
- `Console\Commands\SkillRunCommand` — `skill:run <name> [-- args...] [--backend=claude|codex|gemini|superagent] [--exec=claude|native|fallback] [--fallback-chain=...] [--dry-run]`.
  - `--exec=claude` (default): run on Claude CLI regardless of `--backend`.
  - `--exec=native`: `--backend` selects the target; runs `CompatibilityProbe` + `SkillBodyTranslator` first, prints `[probe]` / `[translate]` lines, then dispatches to the backend runner. Incompatible verdicts print reasons but still run (best-effort, user opted into native).
  - `--exec=fallback`: walks the resolved chain with probe + translate + side-effect lock per hop (see above).
  - Constructor takes optional injected `SkillRegistry`, `Services\CapabilityRegistry`, and `array<string,SkillRunner>` keyed by backend for testability.
- Both commands wired into the standalone `SuperAICore\Console\Application` used by `bin/superaicore`. A Laravel host can wrap them as Artisan commands via the existing service provider pattern.

### Changed
- Framework-agnostic binary renamed `bin/super-ai-core` → `bin/superaicore`; `composer.json` `bin` entry and Symfony Console application name updated. README / README.zh-CN / README.fr / INSTALL.md / INSTALL.zh-CN / INSTALL.fr CLI usage examples updated in lockstep. Laravel package namespace is unchanged (`config/super-ai-core.php`, `super-ai-core::*` views, `AI_CORE_ROUTE_PREFIX` default). `.claude/settings.local.json` permission allowlist updated to the new binary path.

### Tests
- 40 new unit/feature tests (tests/Unit/Registry, tests/Unit/Translator, tests/Unit/Runner, tests/Feature/Console):
  - `FrontmatterParserTest` — 8 cases incl. BOM/CRLF, unclosed frontmatter, flow sequences, quoted values, boolean/null coercion.
  - `SkillRegistryTest` — three-source merge with project-wins, `get()` miss, empty-environment safety.
  - `AgentRegistryTest` — two-source merge, user-only agents, fallback-to-filename-stem for frontmatter without `name:`, empty-environment safety.
  - `SkillBodyTranslatorTest` — gemini rewrite + preamble injection, codex passthrough + preamble, claude identity, word-boundary safety, unmapped-canonical reporting, preamble idempotency on repeated translate.
  - `CompatibilityProbeTest` — per-backend verdicts for claude/codex/gemini × with/without Agent × with/without unmapped canonical tools.
  - `SideEffectDetectorTest` — mtime snapshot diff (create/modify/delete/no-change), stream-json grep for mutating tools, skip-dirs ignored (`.git`), reason-list cap with overflow hint.
  - `FallbackChainTest` — single-hop compatible run, incompatible-first-hop-is-skipped, side-effect locks on first hop (second hop must not run), failure-without-side-effect falls through, all-hops-fail propagates last exit, empty chain.
  - `SkillRunCommandTest` — claude happy path, unknown skill, fallback dry-run walks to claude, native-gemini-incompatible (translates + probes + runs), native-claude-compatible (no probe/translate noise), native-gemini-degraded.
  - `AgentRunCommandTest` — project-agent runs on inferred claude backend, gemini-model agent infers gemini backend, `--backend` overrides inferred backend, unknown-agent non-zero exit, dry-run propagated.
  - `SkillArgumentsTest` — 8 cases across the three recognised shapes (free-form / positional / named), XML rendering + escape of special chars, free-form helper behaviour.
  - `ClaudeSkillRunnerTest` — dry-run announces `--allowedTools` when non-empty; absent flag when frontmatter declares none.
  - `GeminiCommandWriterTest` — 7 cases: first-sync writes both namespaces, second sync is idempotent, stale TOML removed when skill disappears, user-edited TOML preserved against overwrite, user-edited stale kept instead of deleted, user-deleted TOML recreated, `--dry-run` touches no disk.
  - Extended `SkillRunCommandTest` with args-schema rejection (missing required / extra positional) and named-arg XML rendering.
- Full suite: 119 tests / 346 assertions / 1 pre-existing skip (unrelated), zero regressions.

## [0.5.5] — 2026-04-17

Cross-engine compatibility: host apps that ship Claude-Code-style skills can now run them end-to-end on codex-cli and gemini-cli. Combines the work previously tagged as v0.5.3 (BackendCapabilities) and v0.5.4 (SkillManager + MCP cross-sync + Spawn Plan); those tags have been withdrawn.

### Added

**BackendCapabilities — per-engine tool/MCP/agent adapter**
- `SuperAICore\Contracts\BackendCapabilities` — interface exposing `key`, `toolNameMap`, `supportsSubAgents`, `supportsMcp`, `streamFormat`, `mcpConfigPath`, `transformPrompt`, `renderMcpConfig`.
- `Capabilities/ClaudeCapabilities` — canonical: empty tool map, no prompt transform.
- `Capabilities/GeminiCapabilities` — tool-name translation (`WebSearch`→`google_web_search`, `Read`→`read_file`, `Agent`→explicit role-play instructions) + mandatory-behavior preamble that blocks the `codebase_investigator` shortcut on external-research tasks.
- `Capabilities/CodexCapabilities` — preamble flagging no-sub-agent + MCP-only web research, TOML renderer for `[mcp_servers.*]` blocks.
- `Capabilities/SuperAgentCapabilities` — mostly passthrough (SDK path, MCPs wired internally).
- `Services/CapabilityRegistry` — container singleton; falls back to Claude capabilities for unknown backend keys.

**SkillManager + MCP cross-sync**
- `Services/SkillManager` — syncs `.claude/skills/<name>` → `~/.codex/skills/` and `~/.gemini/skills/` via symlinks (recursive-copy fallback on Windows). Optional prefix so multi-host installations don't clobber.
- `Services/McpManager::syncAllBackends()` — single canonical MCP server list (from `codexMcpServers()`) rendered through each `BackendCapabilities` adapter into the native config file (`.claude/settings.json`, `.codex/config.toml` `[mcp_servers.*]`, `.gemini/settings.json`).

**Spawn Plan emulator (sub-agent primitive for CLIs without one)**
- `AgentSpawn/SpawnPlan` — DTO + JSON loader for `_spawn_plan.json`.
- `AgentSpawn/ChildRunner` — interface for per-engine child launchers.
- `AgentSpawn/GeminiChildRunner`, `AgentSpawn/CodexChildRunner` — build a non-interactive CLI child process per agent with combined system+task prompt piped on stdin, stream-json log, per-agent output subdir.
- `AgentSpawn/Orchestrator` — fans out plan entries in parallel up to `$plan->concurrency` (default 4), throttles via `isRunning` poll + 200ms sleep, returns per-agent exit/duration/log report.

### Changed
- `GeminiCapabilities` and `CodexCapabilities` preambles now instruct the model to write `_spawn_plan.json` and stop, instead of "play all roles sequentially" which was unreliable on Flash. The host handles Phase 2 orchestration and Phase 3 consolidation; the consolidation-pass prompt itself is authored by the host orchestrator (e.g. SuperTeam's `ExecuteTask`) — aicore provides the building blocks.

### Verified
- On SuperTeam: a Gemini Flash run that previously produced meta-analyses of the local Laravel codebase (4× `codebase_investigator` calls, zero web searches) now emits 10+ `google_web_search` calls and actually investigates the requested external subject.

## [0.5.2] — 2026-04-17

### Added
- **Gemini CLI as the fourth execution engine.** New backend adapters `gemini_cli` (spawns Google's `gemini` CLI) and `gemini_api` (HTTP against `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`). The "Gemini" engine accepts three provider types: `builtin` (local Google OAuth login), `google-ai` (Google AI Studio API key), `vertex` (Vertex AI via ADC passthrough through the CLI adapter).
- `SuperAICore\Services\GeminiModelResolver` — family-alias rewrites (`pro`/`flash`/`flash-lite` → current full id) + hand-maintained catalog consumed by the providers page fallback.
- `TYPE_GOOGLE_AI = 'google-ai'` provider type; `BACKEND_GEMINI = 'gemini'` added to `AiProvider::BACKENDS` and the `BACKEND_TYPES` matrix.
- `CliStatusDetector` now probes `gemini` on `$PATH`; providers page shows a Gemini card with `npm i -g @google/gemini-cli` as the install hint.
- `ProcessMonitor::DEFAULT_KEYWORDS` includes `gemini` so the process monitor picks up Gemini CLI invocations.
  gemini-sync- New env flags: `AI_CORE_GEMINI_CLI_ENABLED`, `AI_CORE_GEMINI_API_ENABLED`, `GEMINI_CLI_BIN`, `GEMINI_BASE_URL`.
- Gemini 2.5 pricing added to `config.model_pricing` (pro / flash / flash-lite).
- 10 new tests: `GeminiModelResolverTest` (5), plus extensions to `BackendRegistryTest`, `BackendStateTest`, `AiProviderMatrixTest`, `CostCalculatorTest`. Suite is now 44 tests / 119 assertions.

### Changed
- `BackendState::DISPATCHER_TO_ENGINE` extended: `gemini_cli` and `gemini_api` both map to the `gemini` engine, so the runtime on/off switch on `/providers` gates both adapters together.
- `Dispatcher::backendForProvider()` rewritten to dispatch on (engine, type) rather than type alone — needed because `vertex` is now ambiguous (Claude engine uses it for Vertex AI Anthropic, Gemini engine uses it for Vertex AI Gemini).
- `ProviderController::fallbackModels()` now takes the provider's backend so it can return the Gemini catalog for `gemini + vertex` without colliding with the Claude catalog used for `claude + vertex`.

## [0.5.1] — 2026-04-17

### Added
- Configurable table prefix (`config/super-ai-core.php:table_prefix`, env `AI_CORE_TABLE_PREFIX`). Default is `sac_`, so the eight package tables become `sac_ai_providers`, `sac_ai_services`, etc. Set to the empty string to keep the raw names.
- `SuperAICore\Support\TablePrefix` helper read by every migration; `SuperAICore\Models\Concerns\HasConfigurablePrefix` trait applied to all eight models.
- GitHub Actions CI (`.github/workflows/tests.yml`) — matrix across PHP 8.1/8.2/8.3 × Laravel 10/11/12 plus a dedicated `phpunit-no-superagent` job that exercises the SuperAgent-SDK-missing path.
- Real phpunit suite: `phpunit.xml`, `tests/TestCase.php` (on Orchestra Testbench), 9 test classes covering `TablePrefix`, `SuperAgentDetector`, `BackendRegistry`, `BackendState`, `CostCalculator`, `AiProvider` backend→type matrix, `Dispatcher` (with a stub `Backend`), and end-to-end migration + prefix round-trips. 34 tests, 85 assertions, all green on both SDK-present and SDK-missing matrices.

### Changed
- **BREAKING (pre-1.0)** — table names default to the `sac_` prefix. Hosts that installed `v0.5.0` migrations must either set `AI_CORE_TABLE_PREFIX=''` to keep the raw names or rename existing tables.

## [0.5.0] — 2026-04-16

Initial public release. The package consolidates the AI execution stack that used to live inside SuperTeam into a standalone Laravel package with a complete admin UI.

### Added

**Backends**
- `ClaudeCliBackend` — shells out to the `claude` CLI with configurable binary path and timeout.
- `CodexCliBackend` — shells out to the `codex` CLI.
- `SuperAgentBackend` — optional, delegates to `forgeomni/superagent` when the SDK is present; gracefully unavailable otherwise.
- `AnthropicApiBackend` — HTTP backend for the Anthropic Messages API.
- `OpenAiApiBackend` — HTTP backend for the OpenAI Chat Completions API.
- `BackendRegistry` with per-backend enable flags and env-driven configuration.
- `CliStatusDetector` — probes `$PATH` and reports detected CLI versions on the providers page.

**Dispatcher & routing**
- `Dispatcher` — unified entry point: resolves backend, provider, model, then executes and tracks.
- `ProviderResolver` — reads the active provider per task type from `AiProvider` / `AiServiceRouting`.
- `RoutingRepository`, `ProviderRepository`, `ServiceRepository`, `UsageRepository` interfaces, auto-bound to Eloquent implementations.
- `ClaudeModelResolver` / `CodexModelResolver` — resolve effective model from service config, provider default, or backend default.

**Persistence**
- Eight migrations: `integration_configs`, `ai_capabilities`, `ai_services`, `ai_service_routing`, `ai_providers`, `ai_model_settings`, `ai_usage_logs`, `ai_processes`.
- Matching Eloquent models under `SuperAICore\Models\*`.
- `UsageTracker` persists token counts, duration and USD cost on every dispatch.
- `CostCalculator` with a config-driven per-model pricing table (Claude 4.x, GPT-4o family).

**MCP & processes**
- `McpManager` — install, enable, disable and inspect MCP servers.
- `SystemToolManager` — registry of system-level tools exposed to agents.
- `ProcessMonitor` + `ProcessSourceRegistry` + pluggable `ProcessSource` contract; ships with `AiProcessSource` and tables for live process inspection.

**Admin UI**
- Blade views for integrations, providers, AI services, AI models, usage, costs and processes.
- Reusable partials for provider cards, config modals and provider-specific fields.
- `layouts/app.blade.php` with navbar, back-to-host link, and locale switcher.
- Trilingual interface: English, Simplified Chinese (`zh-CN`), French.
- `LocaleController` + cookie-based locale persistence compatible with host middleware.

**HTTP layer**
- Controllers: `IntegrationController`, `ProviderController`, `AiServiceController`, `UsageController`, `CostDashboardController`, `ProcessController`, `LocaleController`.
- Routes registered under a configurable prefix with `['web', 'auth']` middleware; can be disabled entirely via `AI_CORE_ROUTES_ENABLED`.

**CLI**
- Framework-agnostic binary at `bin/super-ai-core`.
- `list-backends` — report availability for every backend in the current environment.
- `call` — send a prompt through any backend with inline credentials and model override.

**Configuration**
- Publishable config (`config/super-ai-core.php`) with env flags for host integration, locale switcher, route/view registration, per-backend toggles, default backend, usage retention, MCP install dir, process monitor toggle, and per-model pricing.

**Documentation**
- Trilingual `README.md` / `README.zh-CN.md` / `README.fr.md`.
- Trilingual `INSTALL.md` / `INSTALL.zh-CN.md` / `INSTALL.fr.md`.
- MIT `LICENSE`.

### Known limitations

- Streaming responses are not yet exposed through the `Dispatcher` return shape.
- Process monitor is disabled by default and requires admin-only middleware wiring in the host app.
- Model pricing table covers Claude 4.x and GPT-4o only; other models fall back to zero cost and must be added to `config.model_pricing`.

[0.6.6]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.6
[0.6.5]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.5
[0.6.2]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.2
[0.6.1]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.1
[0.6.0]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.0
[0.5.9]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.9
[0.5.8]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.8
[0.5.7]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.7
[0.5.6]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.6
[0.5.5]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.5
[0.5.2]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.2
[0.5.1]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.1
[0.5.0]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.0
