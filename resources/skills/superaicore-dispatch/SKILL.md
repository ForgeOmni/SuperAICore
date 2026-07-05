---
name: superaicore-dispatch
description: >
  Delegate a coding task, code review, second opinion, or multi-model
  comparison to another locally-installed AI engine (Claude, Codex, Gemini,
  Copilot, Kimi, Qwen, Cursor, Grok, Kiro, SuperAgent) through the
  `superaicore` CLI. Use when the user asks for a second opinion / peer
  review from a different model, wants a task run on a specific other
  engine, or wants the same question compared across models.
---

# superaicore-dispatch

Route a task to another local AI CLI via `superaicore send`, keep the
conversation going with `superaicore resume`, and audit past dispatches
with `superaicore runs`. You stay the deciding agent — external models
provide input only.

## Before dispatching

1. **Read the user's preferences** (scenario → model choices). If the user
   explicitly names a target or model, the user's choice wins.

   ```bash
   superaicore preferences show
   ```

2. **Check the routing pool** when unsure what a short name maps to:

   ```bash
   superaicore aliases            # full alias table
   superaicore aliases opus --json  # resolve one target exactly like send does
   ```

3. First-time setup problems? `superaicore doctor` shows which engines are
   installed/authenticated and whether aliases, preferences, and the run
   store are healthy.

## Dispatch a task

```bash
superaicore send <target> "<task>" \
  --cwd "$PWD" --json-result --task-name <name>
```

- `<target>` is an alias (`opus`, `sonnet`, `codex`, `gemini-pro`, `kimi`,
  `qwen`, `grok`, …), a backend name (`claude_cli`), or a raw model id.
- Always pass `--cwd "$PWD"` for project work so the engine sees the repo.
- Review prompts must reference concrete sources: diffs, file paths, logs,
  or explicit line ranges — never "review the code".
- Use `--prompt-file <path>` for long prompts instead of a huge argv.
- Add `--task-name` for anything you may want to find again in `runs list`.
- Add `--stream-progress` to watch live engine output on stderr.

## Interpret the result (`--json-result`)

Never assume the requested target answered — read the metadata:

| field | meaning |
|---|---|
| `ok` / `status` | overall outcome (`ok`, `failed`, `exhausted`, `no_candidates`) |
| `text` | the model's answer |
| `backend_used` / `model_used` | who actually answered |
| `requested_target` / `route_source` | what you asked for and how it resolved |
| `route_trace` | every candidate tried, with per-step status + failure class |
| `degraded` / `degrade_reason` | true when a fallback candidate answered instead of the first choice |
| `failure_class` | on failure: quota, rate_limit, auth, network, tool_policy, validation, or null (runtime) |
| `session_id` | pass to `resume` for follow-ups |
| `run_id` | look it up later with `superaicore runs show <id>` |

Quota/rate-limit/auth/network failures fall through to the next candidate
automatically (`degraded: true`); runtime failures fail closed so you see
the real error.

## Follow-up questions

Resume only when the prior result carried a real `session_id`:

```bash
superaicore resume --session-id <id> "<only the new question>" \
  --json-result --task-name <name>-r2
```

Write only the delta — never re-paste conversation history. Chain further
follow-ups off the **latest** result's `session_id` (engines may fork a
fresh id per resume).

## Audit

```bash
superaicore runs list             # newest dispatches first
superaicore runs show <run-id>    # full result contract + prompt excerpt
```
