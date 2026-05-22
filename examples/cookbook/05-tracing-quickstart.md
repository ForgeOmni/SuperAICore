# 05 — Tracing quickstart (magic-trace black box)

Goal: trigger a Dispatcher trace dump, open it in the bundled viewer, and
understand what each event category means.

## The model

Borrowed from Jane Street's `magic-trace`: the Dispatcher continuously writes
trace events into a 1024-entry in-memory ring buffer. The ring is **never**
flushed unless something interesting happens:

| Trigger | When |
|---|---|
| `error`    | `QuotaExceededException`, `CyberPolicyException`, `ServerOverloadedException`, null result |
| `rotate`   | Provider rotation (`AI_CORE_AUTO_ROTATE`, manual `provider:rotate`) |
| `snapshot` | Agent calls the `snapshot` tool / writes `[SNAPSHOT: ...]` |
| `manual`   | `php artisan dispatcher:dump-trace` |
| `timeout`  | Soft-timeout watchdog |

Trigger fires → ring serialized to disk as Chrome Trace Event JSON →
viewable in 4 different tools.

## Enable (already on by default since Wave 1)

```dotenv
# .env (defaults shown — usually no changes needed)
AI_CORE_TRACE_ENABLED=true
AI_CORE_TRACE_RING_SIZE=1024
# AI_CORE_TRACE_STORAGE_PATH=...  # defaults to storage/app/superaicore/traces
```

## Force a dump

```bash
# Dispatch something through PHP first…
php artisan tinker --execute='app(\SuperAICore\Services\Dispatcher::class)->dispatch(["prompt" => "hello world"]);'

# …then dump the ring.
php artisan dispatcher:dump-trace --reason="cookbook demo"

# Output:
# Trace dump written: storage/app/superaicore/traces/trace_superaicore_<sid>_<ts>_manual.json  (1 events)
# Open with: chrome://tracing, https://ui.perfetto.dev, or .claude/design-system/templates/trace-viewer.html
```

## Open it

Three options:

1. **SuperAICore dashboard** — visit `/super-ai-core/traces` (newest first,
   embedded Perfetto loader on click)
2. **Bundled static viewer** — copy
   `.claude/design-system/templates/trace-viewer.html` (SuperTeam repo)
   anywhere, open in browser, drop the `.json` file into the file picker
3. **Perfetto / chrome://tracing** — drag-and-drop the JSON into either UI

## What's in the trace

| `cat` | When emitted | Sample `name` |
|---|---|---|
| `llm`      | every Dispatcher call | `llm.dispatch`, `llm.cache_cold` |
| `tool`     | tool use (when SuperAgentBackend instrumented) | `tool.bash`, `tool.read` |
| `agent`    | turn / sub-agent boundary | `agent.turn` |
| `debate`   | DebateOrchestrator rounds | `debate.round_1`, `debate.judge`, `debate.total` |
| `budget`   | CostAutopilot decisions | `budget.tier_change` |
| `provider` | rotate / disabled | `provider.rotate`, `provider.disabled` |
| `marker`   | `snapshot` tool invocation | `snapshot` |
| `error`    | exceptions caught | `error.unrecoverable`, `provider.error` |

Each event carries `args` with the relevant numbers — token counts, cost,
cache reads, model id, provider id, error class, etc. Click any bar in the
viewer to inspect.

## Inspect from the shell

```bash
# How many events are currently in the ring?
php artisan tinker --execute='echo \SuperAICore\Tracing\TraceCollector::getInstance()->getRing()->count();'

# Latest dump?
ls -lt storage/app/superaicore/traces | head -5
```

## Disable

```dotenv
AI_CORE_TRACE_ENABLED=false
```

Every emit becomes a no-op; no files written. The ring still exists but stays
empty. Useful for ultra-tight benchmark loops.

## Cross-repo wire format

Identical format on the SuperAgent and SuperTeam (PptJob) sides — full
contract in SuperTeam `.claude/refs/ref-trace-format.md`. A single Perfetto
session can load one trace from each producer side-by-side.

## See also

- 03 — Provider rotation (auto-rotate fires a trace dump)
- SuperAgent cookbook 02 — Debate timeline visualization
- SuperTeam CONVENTIONS.md §15 — `[SNAPSHOT: ...]` markers
