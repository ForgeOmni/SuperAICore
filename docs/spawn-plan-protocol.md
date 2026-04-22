# Spawn-Plan Protocol — formal spec

**Phase:** C (per `host-spawn-uplift-roadmap.md`)
**Implements:** `AgentSpawn\Pipeline` + `BackendCapabilities::spawnPreamble()` + `BackendCapabilities::consolidationPrompt()`.

---

## Why

Some CLIs (codex, gemini) don't expose a native sub-agent primitive
the way Claude Code does (its `Agent` tool with `subagent_type:`). When
a skill says "spawn 5 agents in parallel and consolidate", those CLIs
have no way to comply natively — they'd either (a) play all 5 roles
sequentially in one run (slow, context-bloated, no isolation), or (b)
ignore the instruction entirely.

The Spawn-Plan Protocol is a **3-phase emulation dance** that lets
those CLIs participate in the same parallel-agents skill design as
Claude:

1. **Phase 1 (preamble)** — when the model gets the prompt, a runtime
   preamble tells it: "you can't actually spawn sub-agents; instead,
   write a plan file describing what agents you would have spawned,
   then stop."
2. **Phase 2 (fanout)** — the host detects the plan file, reads it,
   and spawns N child CLI processes in parallel, each running one
   agent's role in its own subdir.
3. **Phase 3 (consolidation)** — the host re-invokes the same parent
   CLI with a consolidation prompt that points at every child's
   output subdir. The model reads the children's outputs and writes
   the final summary/meta files.

Before Phase C this dance lived in each downstream host
(SuperTeam's `ExecuteTask::maybeRunSpawnPlan` + `runConsolidationPass`,
~150 lines of orchestration glue). Phase C lifts it into SuperAICore so
every host inherits the protocol — and so adding a new CLI that needs
the emulation only requires implementing `spawnPreamble()` +
`consolidationPrompt()` on its `BackendCapabilities`.

---

## Phase 1 — preamble

`BackendCapabilities::spawnPreamble(string $outputDir): string`

Returns a chunk of prompt text instructing the model to:

1. Decide which agents to spawn (typically 2–5).
2. For each, read the role definition from
   `.claude/agents/<agent-name>.md`.
3. Write `_spawn_plan.json` in the run's output directory and stop.

The plan file format (`AgentSpawn\SpawnPlan` parses this):

```json
{
  "version": 1,
  "concurrency": 4,
  "agents": [
    {
      "name": "cto-vogels",
      "system_prompt": "...full role.md contents...",
      "task_prompt": "role-specific instructions for THIS run",
      "output_subdir": "cto-vogels"
    },
    {
      "name": "ceo-bezos",
      "system_prompt": "...",
      "task_prompt": "...",
      "output_subdir": "ceo-bezos"
    }
  ]
}
```

Implementations that ship today:
- `CodexCapabilities::spawnPreamble()` — codex-rs preamble (the
  `PREAMBLE` constant the existing `transformPrompt()` already injects)
- `GeminiCapabilities::spawnPreamble()` — gemini-cli preamble (mentions
  `read_file` instead of `Read`, etc.)
- `ClaudeCapabilities` / `KiroCapabilities` / `CopilotCapabilities` /
  `SuperAgentCapabilities` — return `''` (don't participate).

Hosts can call `spawnPreamble()` directly when building a prompt, or
rely on the existing `transformPrompt()` which already prepends the
preamble for codex/gemini. Phase 1 is **passive** from `Pipeline`'s
perspective — it doesn't run anything, just expects the prompt to have
contained the preamble.

---

## Phase 2 — parallel fanout

`AgentSpawn\Orchestrator` (already shipped pre-Phase-C) takes a
`SpawnPlan` and runs every agent in parallel (bounded by
`$plan->concurrency`). Per-engine `ChildRunner` implementations build
the actual subprocesses:

- `AgentSpawn\CodexChildRunner` — spawns `codex exec` with a child
  prompt that bakes in the agent's system role + task.
- `AgentSpawn\GeminiChildRunner` — spawns `gemini -p` with the same.

Output goes to `$outputRoot/<agent.output_subdir>/`. Each child writes
a `run.log` and (typically) one or more `.md` output files following
the role's instructions.

`Orchestrator::run()` returns a per-agent report:

```php
[
  ['name' => 'cto-vogels', 'exit' => 0, 'log' => '...', 'duration_ms' => 8421, 'error' => null],
  ['name' => 'ceo-bezos',  'exit' => 1, 'log' => '...', 'duration_ms' => 4112, 'error' => 'rate limited'],
]
```

`Pipeline` runs Phase 2 transparently as part of `maybeRun()`. Hosts
that want to drive Phase 2 standalone (e.g. for custom orchestration)
can call `Orchestrator::forBackend($engineKey)->run(...)` directly.

---

## Phase 3 — consolidation re-call

`BackendCapabilities::consolidationPrompt(SpawnPlan $plan, array $report, string $outputDir): string`

Returns the prompt to feed back into the **same** parent CLI for the
consolidation pass. The default template (in
`Capabilities\SpawnConsolidationPrompt`) asks the model to:

1. Read every agent's output files (`.md` / `.csv`) from their subdir.
2. Synthesize findings — connect insights across agents, note
   agreements and disagreements.
3. Produce three summary files in `$outputDir`:
   - `摘要.md` — executive summary
   - `思维导图.md` — markdown heading tree
   - `流程图.md` — Mermaid flowchart of the actual execution
4. Do NOT write `_spawn_plan.json` again. Do NOT spawn new agents.

`Pipeline` re-invokes `Dispatcher::dispatch(['stream' => true, ...])`
with this prompt, the same backend, and metadata stamping
`capability=<original>.consolidate` so dashboards can group
consolidation rows separately from first passes.

The merged envelope `Pipeline::maybeRun()` returns combines:
- `output` — first pass + `\n--- consolidation ---\n` + consolidation
- `summary` — consolidation text only (the user-facing answer)
- `usage` — consolidation pass usage (most recent)
- `costUsd` / `shadowCostUsd` — sum of both passes
- `durationMs` — sum of both passes
- `spawnReport` — Phase 2 fanout report
- `usageLogId` — consolidation pass row id

---

## Lifecycle from a host's perspective

```php
$envelope = app(\SuperAICore\Runner\TaskRunner::class)->run(
    backend: 'codex_cli',
    prompt:  $userPromptWithPreambleApplied,  // transformPrompt() already did this
    options: [
        'spawn_plan_dir' => $outputDir,        // ← Phase C activation switch
        'log_file'       => $logFile,
        'mcp_mode'       => 'inherit',
        'task_type'      => 'tasks.run',
        'capability'     => $task->type,
        'user_id'        => $userId,
        'metadata'       => ['task_id' => $task->id],
    ],
);

// $envelope->success         — true if the *consolidation* pass succeeded
// $envelope->summary         — consolidation text
// $envelope->spawnReport     — per-agent fanout report (when Phase C ran)
// $envelope->output          — first pass + consolidation, joined
```

`spawn_plan_dir` is the load-bearing flag. When absent, TaskRunner
acts exactly like Phase B — single-pass dispatch, no Pipeline
involvement. When present:
- Pipeline checks for `_spawn_plan.json` in the dir (and a few
  fallbacks like the cwd).
- If no plan written → returns null → TaskRunner keeps the
  first-pass envelope.
- If the backend's capability returns `''` for `consolidationPrompt`
  (claude/kiro/copilot/superagent) → returns null → first-pass
  envelope kept.
- Otherwise: runs Orchestrator → re-invokes Dispatcher → returns the
  merged envelope.

---

## Adding the protocol to a new CLI

Implement two methods on the new `BackendCapabilities`:

```php
class FutureCliCapabilities implements BackendCapabilities
{
    // ... existing methods ...

    public function spawnPreamble(string $outputDir): string
    {
        return "Your preamble — see CodexCapabilities::PREAMBLE for the model.\n";
    }

    public function consolidationPrompt(\SuperAICore\AgentSpawn\SpawnPlan $plan, array $report, string $outputDir): string
    {
        // Default template works for most cases:
        return \SuperAICore\Capabilities\SpawnConsolidationPrompt::build($plan, $report, $outputDir);

        // Or build your own if the new CLI needs a different file
        // convention / language / agent-output reading idiom.
    }
}
```

Add a `ChildRunner` for Phase 2:

```php
class FutureChildRunner implements \SuperAICore\AgentSpawn\ChildRunner
{
    public function build(array $agent, string $outputRoot, string $logFile, string $projectRoot, array $env, ?string $model): \Symfony\Component\Process\Process
    {
        // Build the per-agent subprocess command — see
        // CodexChildRunner / GeminiChildRunner for examples.
    }
}
```

Wire it into `Orchestrator::forBackend()`:

```php
// in Orchestrator::forBackend()
$runner = match ($backend) {
    AiProvider::BACKEND_CODEX  => new CodexChildRunner(...),
    AiProvider::BACKEND_GEMINI => new GeminiChildRunner(...),
    AiProvider::BACKEND_FUTURE => new FutureChildRunner(...),  // ← new
    default => throw new \InvalidArgumentException(...),
};
```

That's it — the new CLI now participates in the protocol with no host
code change.

---

## Why is `Pipeline::maybeRun()` invoked even when the model didn't write a plan?

For ergonomics. Hosts pass `spawn_plan_dir` for *every* task that
*might* spawn agents — they don't know in advance whether the model
will choose to use the protocol. `Pipeline` checks for the plan file
cheaply (one stat call) and exits null when absent, so the cost of
"wired but unused" is essentially zero.

Implementation note: the check considers both the canonical location
(`$outputDir/_spawn_plan.json`) and the cwd at dispatch time, since
gemini-cli in particular doesn't always honor the absolute-path
instruction. Found-but-misplaced plans are moved into the canonical
location before `SpawnPlan::fromFile()` reads them.

---

## Migration tip for SuperTeam hosts

Once SuperTeam upgrades to the release that bundles Phase C:

```php
// BEFORE — host's executeTask() included this:
if ($result['success']) {
    $spawnResult = $this->maybeRunSpawnPlan(
        $task, $outputDir, $timestamp, $resolvedBackend, $options, $result
    );
    if ($spawnResult !== null) {
        $result = $spawnResult;
    }
}

// AFTER — Pipeline runs inside TaskRunner. The two methods become deletable:
//   - ExecuteTask::maybeRunSpawnPlan()      (~80 lines)
//   - ExecuteTask::runConsolidationPass()   (~70 lines)
// Just pass spawn_plan_dir to TaskRunner::run() and the protocol fires
// automatically.
```

`Capabilities\SpawnConsolidationPrompt` produces the same `摘要.md` /
`思维导图.md` / `流程图.md` filenames SuperTeam was already targeting,
so downstream PPT / report-rendering pipelines that look for those
filenames keep working.

---

## Open questions resolved during Phase C

- **Spawn preamble localization** — N/A, kept English. The preamble is
  internal model instruction, not user-facing UI text. Future hosts
  with non-English models can override `spawnPreamble()` on a custom
  Capability if needed.
- **Output filename convention** — `摘要.md` / `思维导图.md` /
  `流程图.md` baked into `SpawnConsolidationPrompt::build()` because
  that's what every existing downstream pipeline expects. Hosts with
  different conventions should NOT override this class — instead, build
  their own consolidation prompt and feed it directly into
  `TaskRunner::run()` as the prompt for a separate dispatch, skipping
  `BackendCapabilities::consolidationPrompt()`.
- **Concurrency limits** — read from `$plan->concurrency` (clamped to
  1–8 by `SpawnPlan::fromFile()`). Hosts can adjust by editing the
  plan file before `Pipeline` reads it (rare, advanced).
