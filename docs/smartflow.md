# SmartFlow — cross-CLI dynamic workflows

SmartFlow is SuperAICore's port of Claude Code's built-in `Workflow` engine,
retargeted so the unit of routing is a **CLI/backend** rather than an API model.
It tracks the SuperAgent SDK's cross-*model* SmartFlow (SDK 1.1.0) but drives the
execution backends SuperAICore already manages:

`claude_cli` · `codex_cli` · `gemini_cli` · `copilot_cli` · `kimi_cli` ·
`qwen_cli` · `cursor_cli` · `grok_cli` · `kiro_cli` · `superagent` ·
`anthropic_api` · `openai_api` · `gemini_api`

One set of primitives drives any of them, so a single flow can **plan on one CLI
and review on others, concurrently** — different CLIs collaborating on one task.

It is **additive**: the Dispatcher, AgentSpawn, and the Squad/Team/Smart/Auto
orchestrators are untouched. SmartFlow is a new module (`src/SmartFlow/`) plus a
`superaicore flow` command.

## Quick start

```bash
# list the built-in flows
superaicore flow list

# inspect one
superaicore flow show cross-cli-review

# rehearse end-to-end at ZERO cost (no CLI is actually invoked)
superaicore flow run cross-cli-review --args diff=@my.diff --rehearse

# run for real: Claude summarizes, Codex + Gemini review in parallel, Claude decides
superaicore flow run cross-cli-review --args diff=@my.diff

# resume a prior run — the unchanged prefix replays from cache, zero cost
superaicore flow run cross-cli-dev --args goal="add caching" --resume <runId>
```

Inside a Laravel host the same command is available as `php artisan flow ...`.

## The primitives

A flow body is a `callable(Flow $flow): mixed` (or a YAML file compiled to one).
The `$flow` context exposes:

| Primitive | What it does |
|---|---|
| `agent($prompt, $opts)` | one cross-CLI call; returns a validated array (with `schema`), the raw string, or `$flow->SKIP` |
| `call($prompt, $opts)` | a **deferred** call, for use inside `parallel()` / `pipeline()` |
| `parallel([$a, $b, …])` | barrier fan-out; deferred calls run concurrently (process pool) |
| `pipeline($items, ...$stages)` | each item through each stage; calls within a stage run concurrently |
| `gate($name, $check, $opts)` | acceptance checkpoint with optional `fallback`/`relay` and `required` |
| `council($claim, $lenses)` | perspective-diverse vote; each lens can pin a different CLI |
| `budget` | shared USD/token ceiling for the whole run |
| `log()` / `phase()` | narration / progress grouping |
| `$flow->SKIP`, `$flow->keep($arr)` | the skip sentinel + a helper to strip nulls/skips |

`$opts` keys: `backend` (the CLI — `provider` is an accepted alias), `model`,
`role` (a persona), `system`, `schema`, `temperature`, `max_tokens`, `label`,
`provider_config` (credentials for API backends; CLI backends ignore it).

### PHP example

```php
use SuperAICore\SmartFlow\FlowEngine;
use SuperAICore\SmartFlow\FlowDefinition;
use SuperAICore\SmartFlow\FlowOptions;

$planSchema = ['type' => 'object', 'required' => ['steps'],
    'properties' => ['steps' => ['type' => 'array', 'items' => ['type' => 'string']]]];

$flow = FlowDefinition::make('my-flow', 'plan then review across CLIs', function ($flow) use ($planSchema) {
    $flow->phase('Plan');
    $plan = $flow->agent("Plan: {$flow->args['goal']}", [
        'role' => 'planner', 'backend' => 'claude_cli', 'schema' => $planSchema,
    ]);

    $flow->phase('Review');
    $reviews = $flow->parallel([
        $flow->call("Review for correctness:\n" . json_encode($plan), ['role' => 'reviewer', 'backend' => 'codex_cli']),
        $flow->call("Review for security:\n" . json_encode($plan),    ['role' => 'reviewer', 'backend' => 'gemini_cli']),
    ]);

    $verdict = $flow->council('The plan is complete and safe', ['correctness', 'completeness', 'security']);
    return ['plan' => $plan, 'reviews' => $flow->keep($reviews), 'passed' => $verdict['passed']];
});

$result = (new FlowEngine())->run($flow, ['goal' => 'ship the feature']);
// $result->value, $result->costUsd(), $result->ledger, $result->runId
```

## Authoring flows in YAML

Static flows live in `resources/flows/*.yaml` and compile to the same engine via
`YamlFlowLoader`. Drop your own under `./flows`, `./.superaicore/flows`, or a dir
named in `super-ai-core.smartflow.flows_dir`.

```yaml
name: cross-cli-review
description: Claude summarizes; Codex + Gemini review in parallel; Claude decides.
phases: [{title: Summarize}, {title: Review}, {title: Synthesize}]
defaults: {backend: claude_cli}
schemas:
  verdict:
    type: object
    required: [decision, summary]
    properties:
      decision: {type: string, enum: [approve, request_changes, comment]}
      summary: {type: string}
steps:
  - {name: summary, phase: Summarize, role: reviewer, backend: claude_cli, prompt: "Summarize:\n{{args.diff}}"}
  - name: reviews
    phase: Review
    strategy: parallel
    agents:
      - {role: reviewer, backend: codex_cli,  prompt: "Correctness:\n{{steps.summary.output}}"}
      - {role: reviewer, backend: gemini_cli, prompt: "Security:\n{{steps.summary.output}}"}
  - {name: verdict, phase: Synthesize, role: chair, backend: claude_cli, schema: verdict, prompt: "Decide:\n{{steps.reviews.output}}"}
return: verdict
```

**Templating** — `{{args.x}}`, `{{steps.<name>.output}}`, `{{item}}` (in
pipelines), and dotted paths into structured output (`{{steps.plan.output.title}}`).
**Strategies** — `solo` (default), `parallel`, `pipeline`, `gate`. **Gate
checks** — `nonempty:{{…}}`, `equals:{{a}}|{{b}}`, `contains:{{a}}|needle`.

## Structured output: the 3-layer safety net

CLIs return free prose, so when you pass a `schema` SmartFlow bakes it into the
prompt and then recovers a valid value through three escalating layers:

1. **native / submitted** — the whole reply parses to schema-valid JSON;
2. **submitted** — a fenced ```` ```json ```` block parses and validates;
3. **extracted** — a regex sniffs the first `{…}` / `[…]` out of the prose.

If none validates, the call returns the **`SKIP`** sentinel instead of crashing,
so a fan-out can `$flow->keep(...)` the bad replies out of the result set.

## Resume & the call-ledger

Every run appends a JSONL ledger under `~/.superaicore/flows/<runId>.jsonl`
(override with `SUPERAICORE_FLOW_DIR` or `smartflow.ledger_dir`). Each agent call
gets a content-addressed signature derived from what you *declared* (prompt,
schema, role, backend, model). `--resume <runId>` recomputes signatures in order
and replays the **longest unchanged prefix** from cache at zero cost; the first
call whose signature differs — and everything after it — runs live. Same flow +
same args + unchanged code ⇒ a fully cached replay.

## Rehearsal (zero-cost)

`--rehearse` (file ledger) and `--dry-run` (in-memory ledger) run a flow
end-to-end **without invoking any CLI**: schema calls get a deterministic
schema-conforming stub, bare calls get a labelled placeholder, cost is `$0`. Use
it to validate a flow's shape, templating, and gates on a machine with no CLIs
installed — every built-in flow is guaranteed to rehearse green.

## Configuration

`config/super-ai-core.php` → `smartflow`:

```php
'smartflow' => [
    'enabled'         => true,
    'default_backend' => null,   // fallback CLI for calls/personas that don't pin one (→ claude_cli)
    'default_model'   => null,
    'concurrency'     => 4,       // max parallel CLI workers (process pool)
    'ledger_dir'      => null,    // default ~/.superaicore/flows
    'flows_dir'       => null,    // extra YAML flow dir(s)
    'budget'          => ['usd' => null, 'tokens' => null],
    'personas'        => [],       // role => ['backend' => '...', 'model' => '...', 'system' => '...']
],
```

Env: `AI_CORE_SMARTFLOW_ENABLED`, `AI_CORE_SMARTFLOW_DEFAULT_BACKEND`,
`AI_CORE_SMARTFLOW_CONCURRENCY`, `AI_CORE_SMARTFLOW_LEDGER_DIR`,
`AI_CORE_SMARTFLOW_FLOWS_DIR`, `AI_CORE_SMARTFLOW_BUDGET_USD`,
`AI_CORE_SMARTFLOW_BUDGET_TOKENS`, `SUPERAICORE_FLOW_DIR`.

## Federation with superagent (cross-CLI → cross-model)

A SuperAICore flow can **delegate a sub-flow to superagent's own SmartFlow**.
This is the layering the two engines are built for:

- **SuperAICore** is the top-level cross-**CLI** orchestrator: it fans a task out
  across `claude_cli`, `codex_cli`, `gemini_cli`, … and the `superagent` backend.
- **superagent** is a cross-**model** sub-orchestrator: when SuperAICore hands it
  a sub-flow, it fans that out across model *providers* — and can do so either on
  its own terms or to SuperAICore's instructions.

Two delegation modes, mirroring "superagent 还可以再一次自行分发或者按照本项目的指示分发":

| Mode | Call | What superagent does |
|---|---|---|
| **named** — superagent self-dispatches (`自行分发`) | `$flow->delegate('research-trio', ['flow_args' => […]])` | runs one of its OWN registered flows; it owns the internal fan-out. You may still steer the model tier with `delegate_provider` / `delegate_model`. |
| **spec** — superagent runs your structure (`按照本项目的指示分发`) | `$flow->delegate('', ['spec' => […flow spec…], 'flow_args' => […]])` | compiles and runs a flow whose steps **SuperAICore authored** — superagent is purely the executor. |

A delegated call is just a special agent call: it flows through the same ledger,
budget, resume, and `parallel()`/`pipeline()` machinery, so its cost federates
into the parent budget and you can fan out several delegations concurrently. In
rehearsal, the delegated SDK flow rehearses too — the whole nested run stays
zero-cost.

```php
$flow->phase('Research (delegated)');
// superagent self-dispatches its cross-model research-trio flow
$findings = $flow->delegate('research-trio', [
    'flow_args' => ['topic' => $flow->args['goal']],
    'delegate_provider' => 'openai',   // steer superagent's model tier
]);

// or: superagent runs a flow SuperAICore authored (provider-based, cross-model)
$brief = $flow->delegate('', ['spec' => [
    'name' => 'mini-brief',
    'steps' => [
        ['name' => 'gather', 'role' => 'researcher', 'provider' => 'openai',    'prompt' => 'research {{args.q}}'],
        ['name' => 'write',  'role' => 'writer',     'provider' => 'anthropic', 'prompt' => "summarize:\n{{steps.gather.output}}"],
    ],
    'return' => 'write',
], 'flow_args' => ['q' => $flow->args['goal']]]);
```

> The **inline spec uses the SuperAgent SDK's schema** (steps route across model
> `provider`s, not CLIs) — it is executed by superagent's engine, not
> SuperAICore's. A named delegation requires the named flow to exist in the SDK's
> registry (`superagent flow list`). If the SDK isn't installed, a delegate call
> fails gracefully (empty / `SKIP`) without crashing the parent flow.

In YAML, use `strategy: delegate` (see `resources/flows/cross-cli-federated.yaml`):

```yaml
- name: research
  strategy: delegate
  delegate: research-trio        # named SDK flow (or: spec: {...} for inline)
  provider: "{{args.research_provider}}"
  flow_args:
    topic: "{{args.goal}}"
```

## How it relates to the other orchestrators

- **Smart / Squad / Auto** decompose a task and route subtasks; they're driven by
  heuristics and the Dispatcher.
- **AgentSpawn** is the 3-phase spawn-plan protocol for CLIs without a native
  Agent tool.
- **SmartFlow** is for *explicitly authored* multi-step flows where you want
  deterministic control flow (fan-out, pipelines, gates, councils), per-step CLI
  routing, structured output, budgets, rehearsal, and resume — the same shape as
  Claude Code's `Workflow`, made cross-CLI.
