# SmartFlow (SuperAICore) — intentional parallel port, NOT a copy to dedup

This module shares most file names with the SDK's `SuperAgent\SmartFlow`, and
a code-clone scanner will flag the overlap. The overlap is **intentional and
must not be mechanically merged**: the two modules are parallel ports of the
same design onto different execution layers.

| Aspect | `SuperAgent\SmartFlow` (SDK) | `SuperAICore\SmartFlow` (this) |
|---|---|---|
| Execution unit | model providers (`LLMProvider`) | CLI backends (`SuperAICore\Contracts\Backend`) |
| Default runner | `FlowAgentRunner` | `BackendAgentRunner` (+ `ProcessPool` for CLI concurrency) |
| Ledger dir | `~/.superagent/flows` (`SUPERAGENT_FLOW_DIR`) | `~/.superaicore/flows` (`SUPERAICORE_FLOW_DIR`) |
| Config keys | `superagent.smartflow.*` | `super-ai-core.smartflow.*` |
| Ledger row identity | `provider` | `backend` |
| Host-only classes | — | `BackendAgentRunner`, `Delegation`, `SuperAgentFlowBridge` |

`SuperAgentFlowBridge` is the federation point: a SuperAICore (cross-CLI) flow
can delegate a sub-flow to the SDK's cross-model engine.

If a future refactor extracts a shared generic core, it must live in the SDK
and be consumed here — do not "fix" the duplication by aliasing these classes
to the SDK versions; the semantics differ (see the table).

The former verbatim duplicates in this package (`Tracing\RingBuffer`,
`Tracing\TraceEvent`, `Tracing\TraceWriter`) were converted to conditional
`class_alias` shims of the SDK classes in v1.1.12 (with classmap-excluded
fallback copies under `Tracing/Fallback/` so the degraded no-SDK path keeps
working) — that is the correct pattern for true copies.
`Arrow\ArrowSerializer` stays host-owned because it is a superset (adds
`fromRowsViaCli()` / `detectExternalCli()`); the SDK carries the trimmed
copy.
