# ai-dispatch parity — alias send, session resume, run archive, preferences, doctor (1.1.0)

Borrowed from [rennzhang/ai-dispatch](https://github.com/rennzhang/ai-dispatch),
a Go runtime whose job is: *let one AI agent hand a task to another local AI
CLI without knowing that CLI's flags*. SuperAICore already had the heavy
machinery (Dispatcher, TaskRunner fallback, engine catalog); this wave adds
the five things ai-dispatch had that we did not.

Everything is additive — the Dispatcher, TaskRunner, orchestrators, and
SmartFlow are untouched (only container-safety guards were hardened, see
[Standalone hardening](#standalone-hardening)).

## 1. Unified short-name routing — `AliasRouter` + `superaicore aliases`

Previously an alias like `opus` only resolved *within* an already-chosen
backend (`ClaudeModelResolver`). Now one token resolves backend AND model in
one step, to an **ordered candidate pool**:

```bash
superaicore aliases                 # full table: alias → candidate chain
superaicore aliases opus --json     # resolve one target exactly like send does
```

Resolution precedence (mirrors ai-dispatch):

1. **user config** — `super-ai-core.dispatch.aliases`
2. **built-in registry** — `AliasRouter::BUILTIN` (fable/opus/sonnet/haiku → claude_cli,
   codex, gemini-pro/-flash, copilot, kimi, qwen, cursor/composer, grok, kiro,
   superagent)
3. **backend passthrough** — `claude_cli`, `superagent`, … route as-is
4. **inference** — model-id substring → engine CLI (`claude-*` → claude_cli,
   `gpt*`/`*codex*` → codex_cli, …); anything else rides the default backend

Config accepts full maps, compact `backend:model` strings, or a single string:

```php
'dispatch' => [
    'aliases' => [
        'reviewer' => [
            ['backend' => 'claude_cli', 'model' => 'opus'],
            'gemini_cli:pro',                    // tried second
        ],
        'mimo' => 'superagent:mimo-v2.5-pro',
    ],
],
```

## 2. `superaicore send` — one-shot dispatch with a transparent result contract

```bash
superaicore send opus "review the diff in HEAD~1" \
  --cwd "$PWD" --json-result --task-name review-opus
```

Candidates are tried in order. Failure classes `quota` / `rate_limit` /
`auth` / `network` (configurable via `dispatch.retry_on_classes`, taxonomy
shared with `task_fallback.failure_classes`) fall through to the next
candidate; **anything else fails closed** — fallback never hides a broken
task. The `--json-result` contract:

| field | meaning |
|---|---|
| `ok`, `status` | `ok` \| `failed` (fail-closed) \| `exhausted` \| `no_candidates` |
| `text` | the answer |
| `requested_target`, `route_source` | what was asked, and which resolution tier answered (`config`/`builtin`/`backend`/`inference`/`default`/`resume`) |
| `backend_used`, `model_used` | who actually answered — never assume it's what you asked for |
| `route_trace[]` | every candidate attempt: status, reason, failure_class, matched_pattern, duration_ms |
| `degraded`, `degrade_reason` | true when a non-first candidate answered |
| `failure_class` | on failure: quota / rate_limit / auth / network / tool_policy / validation / null (runtime) |
| `session_id` | feed to `resume` |
| `cost_usd`, `usage`, `log_file`, `duration_ms`, `run_id` | accounting + audit handles |

Flags: `--prompt-file` (long prompts), `--stream-progress` (live engine
output on stderr), `--system`, `--timeout`, `--session-id`, `--no-check`
(skip the `isAvailable()` pre-check).

Dispatches run through the normal `Dispatcher` streaming path, so usage
rows, cost attribution, tracing, and the process monitor all see them
(`usage_source: dispatch_send`).

## 3. Real session resume — `superaicore resume`

```bash
superaicore resume --session-id <id> "only the new question" --json-result
```

- The **run store** (below) remembers which backend/model owns a session, so
  the caller never restates it (`--backend`/`--model` override for sessions
  the store doesn't know).
- `ClaudeCliBackend` now accepts `resume_session_id` and passes `--resume
  <id>` (mutually exclusive with `--session-id`); both `generate()` and
  `stream()` envelopes now surface `session_id`.
- `CodexCliBackend` captures the `thread.started` → `thread_id` off the
  JSONL stream (surfaced as `session_id`) and resumes via
  `codex exec resume <thread_id>`.
- Resume never falls back to a different engine — the conversation lives in
  ONE engine's session store.
- Engines may fork a fresh id per resume: always chain follow-ups off the
  **latest** result's `session_id`.

## 4. Run archive — `RunStore` + `superaicore runs`

Every `send`/`resume` writes one JSON file (full contract + prompt excerpt +
cwd) to `~/.superaicore/runs` (override: `dispatch.runs_path` config or
`AI_CORE_RUNS_PATH` env):

```bash
superaicore runs list --limit 20
superaicore runs show 20260705T042051Z-a75d69
```

Filesystem-only by design — the usage DB already holds the analytic copy;
this store exists so a headless CLI or a delegating agent can audit results
with zero DB access.

## 5. Agent preferences file — `superaicore preferences`

ai-dispatch's key design idea: scenario→model intelligence lives in **prose
at the calling-agent layer**, not in code. `~/.superaicore/preferences.md`
(override: `dispatch.preferences_path` / `AI_CORE_PREFERENCES_PATH`) is
read by the calling agent before it picks a `send` target; SuperAICore never
parses it.

```bash
superaicore preferences init   # write the starter template (never overwrites)
superaicore preferences show
superaicore preferences path
```

## 6. Delegate-in SKILL — `superaicore skill:install-dispatch`

The mirror image of `superaicore:sync-cli` (which pushes the HOST's skills
out to engines): `resources/skills/superaicore-dispatch/SKILL.md` teaches an
external agent to delegate second-opinion / review / comparison tasks INTO
SuperAICore:

```bash
superaicore skill:install-dispatch                 # claude (default)
superaicore skill:install-dispatch --agent all     # every known skill dir
superaicore skill:install-dispatch --uninstall     # reverse a prior install
```

Symlinks (or copies) the skill into the agent's skill dir via the existing
`SkillManager`. Known dirs (1.1.5): `~/.claude/skills`, `~/.codex/skills`,
`~/.gemini/skills`, `~/.grok/skills`, `~/.cursor/skills-cursor`
(cursor-agent's own layout), `~/.qwen/skills`. `--uninstall` removes only
the bundled skill names a prior install created — user-authored skills in
the same directory are never candidates.

## 7. `superaicore doctor`

One-stop diagnostic aggregating what `cli:status` / `api:status` show
separately, plus the dispatch-layer checks: registered backends, engine
binaries + auth, alias resolvability, preferences file, run-store
writability. `--json` for machines; exit 1 only when nothing can dispatch.

## Standalone hardening

`function_exists('config')` was an unsafe standalone guard: in a dev
checkout the Laravel helpers are autoloaded without a booted container and
`config()` **throws** instead of returning null. New `Support\ConfigValue::get()`
wraps the call; hot paths (`BackendRegistry`, `Dispatcher`, `CostCalculator`,
`TraceCollector`, `BackendState::isEngineDisabled` — which used to fatal on
a missing DB) now degrade to defaults, which also fixed pre-existing
breakage of `bin/superaicore list-backends` / `call` in dev checkouts.

## Where the ideas map from ai-dispatch

| ai-dispatch | SuperAICore |
|---|---|
| `config.json` `models` pool | `dispatch.aliases` + `AliasRouter::BUILTIN` |
| `send <target> --json-result` | `superaicore send` (same contract fields, plus cost/usage) |
| `resume --session-id` | `superaicore resume` + backend `resume_session_id` |
| `~/.ai-dispatch/runs/` + `runs list/show` | `RunStore` + `superaicore runs` |
| `preferences.md` | `superaicore preferences` (+ read step in the SKILL) |
| skill for Claude Code/Codex | `skill:install-dispatch` (`superaicore-dispatch` SKILL) |
| `doctor` / `providers scan` | `superaicore doctor` |
| failure classes (config/quota/timeout/network/runtime/invalid) | shared `task_fallback.failure_classes` taxonomy via `FailureClassifier` |
