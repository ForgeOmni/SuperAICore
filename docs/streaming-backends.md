# Streaming Backends

**Phase:** A (per `host-spawn-uplift-roadmap.md`)
**Audience:** host-app authors who currently spawn CLIs themselves and
want to delegate the spawn / log-tee / Process Monitor / chunk-callback
work to SuperAICore.

---

## Why

`Backend::generate()` was designed for **short calls** — vision routing,
test connections, embeddings, anything where buffering the entire output
in memory and parsing once at exit is fine. Long-running task execution
(a 30-minute claude `-p` run that fans into Spawn-Plan, a Codex pipeline
emitting hundreds of jsonl events, a Kiro session that streams hundreds
of KB of plain text) needs three extra things `generate()` doesn't
provide:

1. **A live tee log file** so the Process Monitor `tail` view, post-hoc
   debugging, and the host's PPT pipeline all see bytes as they arrive.
2. **A row in `ai_processes`** so operators see what's running, the PID,
   the log path, and exit status.
3. **A chunk callback** so a host UI can refresh a "running…" preview
   every few seconds without waiting for subprocess exit.

Plus production-grade configurability:

4. **Per-call timeouts** — the 300s `Backend` constructor default is
   wrong for task workloads.
5. **MCP injection control** — Claude in particular auto-spawns every
   globally-registered MCP server at startup, which can keep the binary
   alive past its final stream event and block the parent.

`StreamingBackend::stream()` packages all five into a single contract.

---

## Quickstart — call sites

### Via Dispatcher (recommended)

```php
$result = app(\SuperAICore\Services\Dispatcher::class)->dispatch([
    'backend'      => 'claude_cli',           // or 'codex_cli', 'gemini_cli', 'kiro_cli', 'copilot_cli'
    'prompt'       => $prompt,
    'model'        => $model,
    'stream'       => true,                    // ← opt-in
    'log_file'     => $logFile,
    'timeout'      => 7200,                    // 2-hour hard cap for long task runs
    'idle_timeout' => 1800,                    // 30 min idle
    'mcp_mode'     => 'empty',                 // claude only — see below
    'task_type'    => 'tasks.run',
    'capability'   => $task->type,
    'user_id'      => auth()->id(),
    'metadata'     => ['task_id' => $task->id],
    'onChunk'      => function (string $chunk, string $stream) use ($taskResult) {
        // Live UI update — runs on every Symfony Process callback
        $taskResult->updateQuietly(['preview' => $chunk]);
    },
]);
```

The dispatcher prefers `stream()` when:
- `options['stream'] === true`, AND
- the resolved backend implements `StreamingBackend`.

Backends that don't implement the contract fall back to `generate()`
silently — callers get the same envelope shape (just without `log_file`
and `exit_code`).

### Direct backend call (skip cost calculation + ai_usage_logs row)

```php
$backend = app(\SuperAICore\Services\BackendRegistry::class)->get('claude_cli');
$result = $backend->stream([
    'prompt'   => $prompt,
    'log_file' => $logFile,
    'timeout'  => 7200,
    'mcp_mode' => 'empty',
    'onChunk'  => $callback,
]);
// $result['log_file'], $result['duration_ms'], $result['exit_code']
// $result['text'], $result['model'], $result['usage']
```

Use this when you don't want the Dispatcher's cost calculator + usage
recording (e.g. internal probing, dev shells).

---

## Options reference

| Key | Type | Default | Notes |
|---|---|---|---|
| `prompt` | string | required | Or `messages` for chat-format input. |
| `model` | ?string | backend default | Resolved per-engine (Kiro dot-format, Codex login mode, etc.). |
| `system` | ?string | none | Claude-only — passed via `--system-prompt`. |
| `provider_config` | array | `[]` | Same shape generate() accepts. |
| `log_file` | ?string | auto | Auto-name via `ProcessRegistrar::defaultLogPath()` when absent. |
| `timeout` | ?int | backend default (300s) | Hard wall-clock cap. |
| `idle_timeout` | ?int | none | Idle = no output for N seconds. |
| `mcp_mode` | string | `'inherit'` | `inherit\|empty\|file` — see below. |
| `mcp_config_file` | ?string | none | Required when `mcp_mode='file'`. |
| `external_label` | ?string | none | Process Monitor row label (`task:42`, `ppt:job:7:strategist`). |
| `metadata` | array | `[]` | Stamped on `ai_processes.metadata` and `ai_usage_logs.metadata`. |
| `onChunk` | ?callable | none | `fn(string $buffer, string $stream): void`. `$stream` is `Process::OUT`/`Process::ERR`. |

---

## `mcp_mode` knob

Each backend that loads MCP servers honors this option:

| Mode | Behavior |
|---|---|
| `inherit` (default) | CLI uses its globally configured MCP set. |
| `empty` | Write a temp `{"mcpServers":{}}` and pass `--mcp-config <file> --strict-mcp-config`. **Required for Claude in headless mode** when the host has many global MCPs — otherwise claude keeps spawning them past its final stream event and blocks parent exit. |
| `file` | Use the explicit `mcp_config_file` path. |

**Per-backend support:**

| Backend | Honors `mcp_mode` | Notes |
|---|---|---|
| `claude_cli` | ✅ | Critical — see above. |
| `codex_cli` | ⏭️ no-op | codex doesn't expose `--mcp-config` per-call. |
| `gemini_cli` | ⏭️ no-op | gemini reads `~/.gemini/settings.json` only. |
| `kiro_cli` | ⏭️ no-op (forward-compat stub) | Kiro 2.x reads `~/.kiro/mcp.json`. Operators who need an empty MCP set should rename the file out before dispatching. |
| `copilot_cli` | ⏭️ no-op | Copilot doesn't load MCP. |

When a backend declines a knob, it ignores silently rather than warning
— callers can always pass `mcp_mode: 'empty'` defensively without
worrying about per-backend dispatch.

---

## Return envelope

Same shape as `generate()`, plus three streaming-only fields:

```php
[
    'text'        => string,    // assistant final response (parsed from stream)
    'model'       => string,    // resolved model id
    'usage'       => array,     // tokens / credits / cache breakdown — backend-specific
    'stop_reason' => ?string,
    // ─── streaming-only ───
    'log_file'    => string,    // where the tee landed
    'duration_ms' => int,       // wall-clock subprocess time
    'exit_code'   => int,       // 0 = success
]
```

When called via Dispatcher, the standard cost columns ride along:
`cost_usd`, `shadow_cost_usd`, `billing_model`, `backend`, `duration_ms`
(dispatcher-level, includes resolution overhead).

When the parser can't extract a final result event (subprocess died
before completing, malformed output), `stream()` still returns an
envelope — `text` is `''`, `usage` is `[]`, but `log_file` /
`duration_ms` / `exit_code` are populated so callers can surface
diagnostics. `null` is reserved for hard failures (empty prompt, throw
during spawn).

---

## Process Monitor integration

`stream()` automatically registers a row in `ai_processes` via
`ProcessRegistrar::start()` and closes it with `finished` / `failed`
based on exit code. The row carries:

| Column | Source |
|---|---|
| `pid` | `Process::getPid()` |
| `backend` | `$this->name()` (e.g. `claude_cli`) |
| `command` | short command summary |
| `log_file` | the tee path |
| `external_label` | `options['external_label']` |
| `metadata` | `options['metadata']` |
| `started_at` / `ended_at` | wall-clock |
| `status` | `running` → `finished`/`failed` |

Outside Laravel (unit tests, standalone CLI use) the registrar is a
silent no-op — `stream()` works unchanged, just without the monitor
row.

---

## Per-backend stream-format quirks

| Backend | Stream format | Notes |
|---|---|---|
| `claude_cli` | NDJSON (`--output-format=stream-json --verbose`) | One event per line: `system_init` → `assistant.*` deltas → terminal `result` event. `parseStreamJson()` walks the capture for the LAST `result` event. |
| `codex_cli` | NDJSON (`exec --json`) | Already JSONL. Parser unchanged from `generate()`. |
| `gemini_cli` | Single JSON blob | `--output-format=json` is one giant blob; chunks during the run are partial JSON. Tee captures everything; parser runs on assembled blob at exit. |
| `kiro_cli` | Plain text | No structured stream. `onChunk` fires per buffer chunk. Final summary line `▸ Credits: X • Time: Ys` parsed at exit. |
| `copilot_cli` | NDJSON (`--output-format=json`) | Already JSONL. Hosts can parse incrementally if desired. |

---

## Open-question resolutions (recorded for traceability)

The roadmap doc enumerates four open questions to resolve during the
Phase A PR. Decisions made:

1. **Streaming for non-CLI backends** — *deferred*. `AnthropicApiBackend`
   / `OpenAiApiBackend` could implement `StreamingBackend` via SSE later,
   but Phase A scope stays CLIs only. Hosts that want SSE today can drop
   to the SuperAgent SDK directly.
2. **Spawn preamble localization** — N/A in Phase A (preamble lives in
   Phase C).
3. **Output dir convention** — `log_file` is an explicit path. Auto-naming
   under `sys_get_temp_dir()` happens only when `log_file` is absent —
   hosts that want predictable paths pass them.
4. **Process Monitor `external_label` naming** — free-form. No host
   namespace prefix enforced. Recommendation in this doc:
   `<feature>:<entity>:<id>` (e.g. `task:42`, `ppt:job:7:strategist`)
   so multi-host installs sharing one `ai_processes` table can grep
   cleanly. Defer enforcement to a future phase if collisions appear.

---

## Migration tips for hosts

If your host currently does this:

```php
// Hand-rolled spawn with stream-json + tee log + manual log handle
$cmd = "cat {$promptFile} | claude -p --output-format stream-json --verbose ... > {$logFile} 2>&1";
$process = new Process(['sh', $execScript], $projectRoot);
$process->setEnv($envVars);
$process->setTimeout(7200);
$process->setIdleTimeout(1800);
$process->run();
$exit = $process->getExitCode();
$output = file_exists($logFile) ? file_get_contents($logFile) : '';
$usage = $myParser->parse($logFile);
$myUsageRecorder->record(...);
```

It collapses to:

```php
$result = app(Dispatcher::class)->dispatch([
    'backend'      => 'claude_cli',
    'prompt'       => $prompt,
    'stream'       => true,
    'log_file'     => $logFile,
    'timeout'      => 7200,
    'idle_timeout' => 1800,
    'mcp_mode'     => 'empty',
    'task_type'    => 'tasks.run',
    'user_id'      => $userId,
    'metadata'     => $metadata,
]);
```

Cost calculation, shadow cost, `ai_usage_logs` row, Process Monitor
row, log tee, exit handling — all bundled. Phase B's `TaskRunner` will
collapse this further.
