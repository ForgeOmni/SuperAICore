# `ai_usage_logs` Idempotency

**Phase:** D (per `host-spawn-uplift-roadmap.md`)
**Builds on:** `EloquentUsageRepository`, `UsageRecorder`, `Dispatcher`.

---

## What it does

Prevents the same logical LLM call from being recorded twice in
`ai_usage_logs`. When `record()` is called with an `idempotency_key`
that matches a row written within the last 60 seconds, the repository
returns that row's id instead of inserting a duplicate.

This is the load-bearing safety net for hosts that haven't fully
migrated to `TaskRunner` and still call both `Dispatcher::dispatch()`
AND their own `UsageRecorder::record()` for the same turn (PPT
`ClaudeStreamUsageParser` is a known case). After Phase D those
duplicate calls auto-collapse to one row with zero host changes.

---

## How keys are picked

`Dispatcher::dispatch()` walks this precedence on every call:

| Source | Key | When it fires |
|---|---|---|
| `options['idempotency_key']` (string) | as supplied (truncated to 80 chars) | Caller knows best — pass your own job id, run UUID, etc. |
| `options['idempotency_key']` (`false`) | none | Explicit opt-out — every record() inserts a row regardless of label. |
| `options['external_label']` (when no explicit key) | `"{backend}:{external_label}"` (truncated to 80 chars) | Default safety net — same `external_label` within 60s ⇒ dedup. |
| _(neither set)_ | none | No dedup — every call inserts a row. |

`external_label` typically looks like `task:42`, `ppt:job:7:strategist`,
`agent:cto-vogels` — host-defined identifiers that stay stable across
the duplicate dispatches a misbehaving host produces, but vary across
legitimately distinct runs.

---

## Quickstart

### Default (no caller change)

```php
$result = app(\SuperAICore\Services\Dispatcher::class)->dispatch([
    'backend'        => 'claude_cli',
    'prompt'         => $prompt,
    'external_label' => "task:{$task->id}",   // ← drives auto-dedup
    'task_type'      => 'tasks.run',
    'user_id'        => auth()->id(),
]);
```

If a host bug or job retry causes the same dispatch to run twice within
60s, both calls return the same `usage_log_id` and only one row lands
on `ai_usage_logs`.

### Explicit key (host knows the run id)

```php
$result = $dispatcher->dispatch([
    'backend'         => 'claude_cli',
    'prompt'          => $prompt,
    'idempotency_key' => "ppt:job:{$pptJob->id}:strategist",
    'task_type'       => 'ppt.strategist',
]);
```

### Opt out (rare — every call legitimately distinct)

```php
$result = $dispatcher->dispatch([
    'backend'         => 'claude_cli',
    'prompt'          => $prompt,
    'external_label'  => "loop:{$task->id}",
    'idempotency_key' => false,   // ← opts out, even with external_label set
]);
```

### Direct UsageRecorder call (bypassing Dispatcher)

```php
app(\SuperAICore\Services\UsageRecorder::class)->record([
    'backend'         => 'claude_cli',
    'model'           => 'claude-sonnet-4-5-20241022',
    'task_type'       => 'tasks.run',
    'input_tokens'    => 100,
    'output_tokens'   => 50,
    'idempotency_key' => "task:{$task->id}",
]);
```

Hosts that already use `UsageRecorder` directly (e.g.
`ClaudeStreamUsageParser::recordAiUsageLog`) can pass an
`idempotency_key` to dedup against future Dispatcher writes for the
same logical turn. Pick a key the Dispatcher would auto-generate
(`"{backend}:{external_label}"`) so the two paths agree.

---

## Window tuning

`EloquentUsageRepository::IDEMPOTENCY_WINDOW_SECONDS = 60` — exposed as
`public const` so callers can read it without depending on a magic
literal.

The window deliberately isn't config-driven: 60s is long enough to
absorb most accidental double-records (Dispatcher writing + a host
that also calls UsageRecorder for the same turn, or a queue retry
that fires within seconds), short enough that two genuinely separate
runs that happen to share a key don't get falsely merged.

If your host has a use case for a different window, override
`EloquentUsageRepository` with a subclass that changes the const, and
rebind it in your service provider.

---

## Schema

The migration adds:

```sql
ALTER TABLE {prefix}ai_usage_logs
    ADD COLUMN idempotency_key VARCHAR(80) NULL AFTER billing_model;

CREATE INDEX ai_usage_logs_idem_created_idx
    ON {prefix}ai_usage_logs (idempotency_key, created_at);
```

The composite index covers the `WHERE idempotency_key = ? AND created_at >= ?`
lookup the repository runs on every keyed `record()`. Even tables
with millions of rows answer the dedup query in microseconds.

`{prefix}` honors `config('super-ai-core.table_prefix')` (default `sac_`).

---

## What this does NOT do

- **Not a replay log.** The window is 60s — outside that, a record()
  call with a previously-seen key just inserts a new row. If you need
  permanent at-most-once semantics, manage that at your job/queue
  layer (e.g. a Redis SET-NX with the run id) before calling Dispatcher.
- **Not a deduplicator for legitimately retried calls.** If a host
  retries a failed dispatch with a new prompt but the same
  `external_label`, the second call is treated as a duplicate. Pick a
  key that includes a retry counter (`"task:42:retry:1"`) when you
  want each retry recorded.
- **Not idempotent for cost calculation.** The cost was computed during
  the original `dispatch()` call; the dedup window only affects the
  `ai_usage_logs` insert. If a CLI was actually invoked twice in 60s,
  you actually spent twice — only the dashboard row collapses, not
  the underlying spend. Use this primarily as a safety net against
  host bugs, not as a way to control LLM costs.

---

## Migration impact for hosts

Once SuperTeam adopts the release that bundles Phase D and runs
`php artisan migrate`:

- `ClaudeStreamUsageParser::recordAiUsageLog()` continues to work
  unchanged. To gain auto-dedup with the Dispatcher writes, pass
  `idempotency_key` matching the same shape the Dispatcher
  auto-generates: `"claude_cli:task:{$task->id}"` (or whatever
  external_label the host's TaskRunner call used).
- The `_usage_recorded` host-side sentinel
  (`ExecuteTask::handle()` line that skips `recordTaskUsage()` when
  Dispatcher already wrote a row) becomes optional — Phase D dedup
  gives the same protection at the repository layer. Hosts can keep
  the sentinel for clarity or remove it.
- Cost dashboards (`/super-ai-core/usage`, `/super-ai-core/costs`)
  see one row per logical call instead of two on hosts that were
  double-recording — totals and per-task aggregations get more
  accurate without any dashboard changes.
