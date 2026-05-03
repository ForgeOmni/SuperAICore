# jcode-inspired roadmap

Larger borrowings from [jcode](https://github.com/1jehuang/jcode) — the
Rust-native multi-session coding-agent harness — that the SuperAICore /
SuperAgent stack would benefit from but that don't fit a single PR.

Each item is sized as **S** (≤200 LoC, single file), **M** (one new module
+ tests), or **L** (multi-file, requires SDK changes too) and notes which
package owns the work.

## Status — updated 2026-05-03 (v0.9.1)

Everything previously listed below has now landed across 0.9.0 and 0.9.1:

| Item | Status | Where |
|---|---|---|
| #1 local Skill reranker | ✅ shipped 0.9.1 | `Services\SemanticSkillReranker` |
| #2 skill semantic activation | ✅ shipped 0.9.1 | `SuperAgent\Skills\SemanticSkillRouter` |
| #3 swarm file-shift events | ✅ shipped 0.9.1 | `SuperAgent\Swarm\FileLedger` + `Events\FileShiftedEvent` |
| #4 ambient mode | ✅ shipped 0.9.1 | `SuperAgent\Swarm\AmbientWorker` |
| #5 agent_grep | ✅ shipped 0.9.1 | `SuperAgent\Tools\Builtin\AgentGrepTool` |
| #6 cross-harness session resume | ✅ shipped 0.9.1 | `SuperAgent\Conversation\Importers\*` + `superagent resume` CLI |
| #7 provider rotate | ✅ shipped 0.9.1 | `provider:rotate` artisan command |
| #8 mermaid on /processes | ✅ shipped 0.9.1 | `super-ai-core.ui.mermaid_enabled` |
| #9 tree-sitter agent_grep | ✅ partial — regex-based PHP/JS/TS/Py/Go | extension point: subclass `AgentGrepTool::extractSymbolMap()` |

Below is the original sizing + design rationale, preserved for reference
when deciding how to extend each piece. Future jcode borrowings get
appended to the **Backlog** section at the bottom.

---

## 1. Local semantic Skill reranker — **M (SuperAICore)**

**jcode behaviour.** Skills are not preloaded; the conversation is embedded
each turn (MiniLM-L6-v2, 384-dim, ONNX, ~22 MB on disk) and the highest-
cosine skills are injected just-in-time.

**Today in SuperAICore.** `SkillRanker` is pure-PHP BM25 with a CJK-aware
tokeniser and a confidence-weighted telemetry boost. Strong on lexical
matches but weak on intent paraphrase ("write tests" vs "add coverage").

**Proposal.** Ship an optional ONNX reranker that runs over BM25's top-N
results. PHP has [`onnxruntime/onnxruntime-php`](https://github.com/microsoft/onnxruntime-extensions/tree/main/operators)
bindings; bundling MiniLM-L6-v2 doubles the package size, so make it a
*suggested* extra (`composer require forgeomni/superaicore-embeddings`)
that auto-activates when present.

**API.**

```php
$ranked = $ranker->rank($prompt, candidates: $skills, rerankerLimit: 20);
// → BM25 picks 20, ONNX cosine reranks them in-process, top-K returned.
```

**Falls back gracefully** to BM25-only when the extension is absent — no
behaviour change for hosts that don't opt in.

---

## 2. Skill semantic activation — **L (SuperAgent)**

**jcode behaviour.** Skills aren't loaded into the system prompt at agent
start; they're embedded once at install time and only injected when the
turn's embedding cosine-matches above a threshold.

**Today in SuperAgent.** `Memory\Palace\PalaceRetriever` does this for
memories but skills are still loaded eagerly. With a real skill catalog
(20+ skills), the system-prompt token cost balloons.

**Proposal.** Wire `SkillRegistry::find()` through the same vector path
the Memory subsystem uses. Reuse `VectorMemoryProvider`'s embedder so we
don't bundle a second one. Add a `SemanticSkillRouter` that:

1. Indexes each skill's `description` + `triggers` at registration time.
2. On `Agent::run()`, embeds the user prompt and matches against the index.
3. Injects only the top-K above `threshold` into the system prompt.

**Caveat.** Has to interact carefully with `SkillTelemetry` so that skills
which never get injected don't get penalised in BM25 boost calculations.

---

## 3. Swarm file-shift events — **M (SuperAgent)**

**jcode behaviour.** When agent A edits a file that agent B has read,
the shared server notifies B over its message bus. B can ignore it or
re-read the diff before continuing.

**Today in SuperAgent.** `Swarm\` is mature (5 backends, mailbox,
worktrees) but agents have no awareness of files they read being mutated
out from under them.

**Proposal.**

- New event type `Swarm\Events\FileShiftedEvent` carrying `{path, by_agent,
  diff_summary, mtime_before, mtime_after}`.
- `WorktreeManager` hashes every file an agent reads via `read` /
  `agent_grep` tools and stores `{agent_id, path, sha}` in a per-team
  ledger (memory or sqlite — small, ephemeral).
- After every agent's `write` / `edit` tool call, scan the ledger for
  collisions and emit `FileShiftedEvent` to every other agent that
  read the file.
- Agents can subscribe via `AgentMailbox::onFileShifted($cb)` — default
  no-op, opt-in by `BuiltinAgents` that benefit (e.g. `ReviewerAgent`).

---

## 4. Ambient mode for memory consolidation — **M (SuperAgent)**

**jcode behaviour.** Idle conversations trigger background consolidation:
deduplicate memories, detect staleness, surface conflicts. Token cost is
attributed to a separate "ambient" channel so dashboards distinguish
user-facing vs background spend.

**Today in SuperAgent.** `MemoryDeduplicator` runs synchronously inside
the agent loop, blocking the next user turn. `AdaptiveFeedback` is also
synchronous.

**Proposal.**

- Add `Swarm\AmbientWorker` — long-lived low-priority agent that polls
  `MemoryStorage::needsConsolidation()` every N seconds.
- Tag every cost row produced by the worker with `usage_source: 'ambient'`.
- SuperAICore's cost dashboard adds a per-source stacked bar (already has
  the rendering primitives via the existing `By Task Type` card).

---

## 5. Agent grep tool — **M (SuperAgent + SuperAICore)**

**jcode behaviour.** A purpose-built grep that injects file-structure
information (function symbols + their line offsets) into every match,
*plus* harness-level adaptive truncation that omits chunks the agent has
already read this session. Substantial token savings on big repos.

**Today.** Agents use the bash tool's plain grep — output is raw lines
with no semantic context, and the agent often re-reads chunks it already
saw.

**Proposal.**

- New `agent_grep` tool in SuperAgent that:
  - Wraps `ripgrep` (or PHP's pcre on small repos).
  - For PHP / JS / TS / Go / Python files, runs the matched file through
    a tree-sitter grammar (or a language-specific symbol extractor) and
    injects the enclosing function/class names alongside each hit.
  - Maintains a per-session "seen chunks" set keyed by file SHA + line
    range; subsequent calls truncate to a `...truncated; previously
    shown to you in turn N...` marker.
- SuperAICore's `Runner\AgentRunner` exposes `--with-agent-grep` to
  swap the tool registration without changing the agent prompt.

---

## 6. Cross-harness session resume — **L (SuperAgent + SuperAICore)**

**jcode behaviour.** Can resume sessions originally started by Claude
Code, Codex, OpenCode, or pi by parsing each harness's session file
format and translating into jcode's internal message representation.

**Today in SuperAgent.** `Auth\ClaudeCodeCredentials` already reuses an
existing Claude Code login. Conversation resume isn't supported.

**Proposal.**

- `Conversation\HarnessImporter` with one adapter per harness:
  - `ClaudeCodeImporter` reads `~/.claude/projects/<hash>/session-*.jsonl`.
  - `CodexImporter` reads `~/.codex/sessions/rollout-*.jsonl`.
  - Each adapter maps to internal `Message[]` then runs through the
    0.9.5 `Conversation\Transcoder` to emit the wire shape for the
    new active provider.
- New CLI: `superagent resume --from claude --session-id <id>`.
- SuperAICore's `/processes` admin page gains a "Resume in X" dropdown
  per row; routes to a host-supplied callback that hydrates an Agent
  with the imported history.

---

## 7. Provider rotate / failover — **M (SuperAICore)**

**jcode behaviour.** Multi-account `/account` switcher; ran out of
ChatGPT Pro tokens? Swap to your second account in one keystroke.

**Today in SuperAICore.** Multiple `AiProvider` rows can exist per
backend, but switching between them is a manual UI operation.

**Proposal.**

- New artisan command `php artisan provider:rotate <backend>` that:
  - Marks the current active provider inactive.
  - Activates the next provider (by `sort_order`) for the same scope+backend.
  - Optionally accepts `--reason='quota_exceeded'` so the row's metadata
    explains why the rotation happened.
- Auto-trigger from `SuperAgentBackend` when `QuotaExceededException`
  fires twice within N seconds — opt-in via `super-ai-core.auto_rotate`.

---

## 8. Mermaid rendering on /processes — **S (SuperAICore)**

**jcode behaviour.** Side panel + chat render mermaid blocks inline via a
pure-Rust 1800×-faster renderer.

**Today in SuperAICore.** Process Monitor shows raw stream output. Agent
emits `\`\`\`mermaid` blocks but they render as plain text.

**Proposal.** Add the [mermaid.js](https://mermaid.js.org/) CDN to the
admin layout's optional scripts. Process Monitor's transcript pane runs
`mermaid.run({ querySelector: 'pre.mermaid' })` after each chunk arrives.
No backend change.

---

## 9. Agent-grep + tree-sitter PHP

Same as #5, scoped to the SuperAICore side: ship an artisan command
`php artisan agent-grep:install` that vendors the tree-sitter grammars
to `vendor/forgeomni/superaicore/resources/tree-sitter/` so every host
inherits the language coverage.

---

## Sequencing recommendation (historical, kept for context)

| Order | Item | Why this order |
|---|---|---|
| 1 | #7 provider rotate | High operator value, S work, no SDK dependency |
| 2 | #8 mermaid on /processes | Pure UI, S, immediate dashboard win |
| 3 | #4 ambient mode | Cost-attribution clarity unlocks #2's UI value |
| 4 | #1 local Skill reranker | Quality jump for any host with a real skill catalog |
| 5 | #3 swarm file-shift events | Unblocks safe parallel agents in shared worktrees |
| 6 | #5 agent_grep | Token savings compound across every long-running agent |
| 7 | #6 cross-harness resume | Highest infra cost; do once SDK is stable on conversation transcoding |
| 8 | #2 skill semantic activation | Builds on #1; defer until reranker is proven |

---

## Backlog — next jcode borrowings

The items below are still pending. Sized + reasoned the same way as
above so the next round can be picked off without re-planning.

All five B-items below shipped in the SuperAICore 0.9.0 / SuperAgent
0.9.7 cycle. Originally sized + reasoned at the time of planning; left
in place as historical context for future borrowings.

### B1. Side panel + info widgets — **shipped (SuperAICore 0.9.0)**

Bootstrap offcanvas drawer in the admin layout, JS API
`window.SuperAICorePanel.show({title, type, content, footer})` with
HTML / mermaid / JSON / iframe / text body types. Marker grammar
`<!-- side-panel: {…json…} -->` auto-rewrites to a button via the
DOMContentLoaded binder; `[data-side-panel-trigger='{…json…}']` for
server-rendered triggers. First in-tree consumer: `/usage` row metadata
inspector. Toggleable via `super-ai-core.ui.side_panel_enabled`.

### B2. Agent grep tree-sitter upgrade — **shipped (SuperAgent 0.9.7)**

`Tools\Builtin\Symbols\SymbolExtractor` SPI with three reference
implementations: `RegexSymbolExtractor` (default, dependency-free),
`TreeSitterSymbolExtractor` (opt-in, shells out to the `tree-sitter`
CLI for ~15 grammars with hard timeout + graceful degrade),
`CompositeSymbolExtractor` (chains them). `AgentGrepTool` constructor
accepts an optional extractor.

### B3. Native ONNX embedder bundling — **shipped scaffolding (SuperAgent 0.9.7)**

`Memory\Embeddings\EmbeddingProvider` interface + four concretes:
`NullEmbeddingProvider`, `CallableEmbeddingProvider` (auto-detects
batch vs single-text shape), `OllamaEmbeddingProvider`,
`OnnxEmbeddingProvider` (probes for ext-onnxruntime / ankane binding;
clear error pointing at the future `forgeomni/superagent-embeddings`
companion package). `SemanticSkillRouter` accepts both the typed
provider and the legacy callable shape.

### B4. Browser tool + Firefox Agent Bridge — **shipped (SuperAgent 0.9.7 + SuperAICore 0.9.0)**

`Tools\Browser\NativeMessagingTransport` (length-prefixed JSON framing)
+ `Tools\Browser\FirefoxBridge` (high-level RPC) +
`Tools\Builtin\FirefoxBridgeTool` (`browser` tool). On the
SuperAICore side: `Services\BrowserScreenshotStore` +
`ProcessEntry::$latest_screenshot_url` + a yellow `📷 screenshot`
badge on `/processes` rows that opens the inline frame in the
side panel (B1).

### B5. Live cache-cold UI badge — **shipped (SuperAICore 0.9.0)**

Dispatcher writes `cache_warning` into the same `ai_usage_logs.metadata`
insert; `/usage` Recent calls table renders a yellow `❄ cold` badge,
banner-with-drill-in summarises the cold-call count, and a
`Cache-cold only` filter scopes the page (driver-portable
`whereNotNull('metadata->cache_warning')`).

