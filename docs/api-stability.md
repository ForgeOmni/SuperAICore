# API Stability

**Phase:** E (per `host-spawn-uplift-roadmap.md`)
**Effective:** the release that bundles Phases A + B + C + D + E.

---

## What this document promises

Public APIs listed under "Stable" below follow strict
[Semantic Versioning](https://semver.org/spec/v2.0.0.html):

- **PATCH** releases (z in `x.y.z`) — bug fixes and internal changes
  that don't affect the documented behavior or signature.
- **MINOR** releases (y) — additive only. New methods, new options,
  new public properties, new tests. Existing call sites keep working.
- **MAJOR** releases (x) — the only releases allowed to break the
  signatures or behavior of stable APIs. A breaking change requires
  a major-version bump even if the maintainer thinks it's "small".

Hosts can pin `^x.y` in composer.json for a stable API surface. Phase E
ships these guarantees in writing — the maintainer is committing to
not break them at the next minor.

> **Phase E pre-soak caveat (read this if you depend on these APIs):**
> The roadmap for Phase E originally said "ships once A + B + C have
> soaked in production for at least one downstream host". The
> maintainer chose to declare stability before the soak window,
> trading "more conservative" for "faster downstream adoption". If a
> Phase A/B/C/D bug forces a backward-incompatible fix, the maintainer
> will document the issue clearly and bump major rather than
> retroactively rewrite history. Hosts that want extra safety should
> wait one minor release after Phase E lands to let real-world
> traffic shake out edge cases.

---

## Stable APIs

### `Contracts\StreamingBackend` (Phase A)

```php
interface StreamingBackend extends Backend
{
    public function stream(array $options): ?array;
}
```

The option keys documented in `docs/streaming-backends.md` (`prompt`,
`messages`, `system`, `model`, `provider_config`, `log_file`,
`timeout`, `idle_timeout`, `mcp_mode`, `mcp_config_file`,
`external_label`, `metadata`, `onChunk`) are stable. Adding new
optional keys is a minor change. Removing or renaming any of them is
a major break.

The return envelope (`text`, `model`, `usage`, `stop_reason`,
`log_file`, `duration_ms`, `exit_code`) is stable. Adding new fields
is minor; removing or renaming existing fields is major.

### `Support\TeeLogger` (Phase A)

```php
final class TeeLogger
{
    public function __construct(string $path);
    public function write(string $chunk): void;
    public function close(): void;
    public function path(): string;
    public function bytesWritten(): int;
    public function isOpen(): bool;
}
```

Constructor signature, public methods, and "best-effort, never
throws" failure semantics are stable.

### `Backends\Concerns\StreamableProcess` (Phase A)

The `runStreaming()` method signature is stable. Backends that `use`
this trait can rely on the parameter names + return shape across
minor releases.

### `Runner\TaskRunner` (Phase B)

```php
class TaskRunner
{
    public function __construct(Dispatcher $dispatcher, ?Pipeline $pipeline = null, ?LoggerInterface $logger = null);
    public function run(string $backend, string $prompt, array $options = []): TaskResultEnvelope;
}
```

Constructor signature is stable. The order of optional constructor
args (`Pipeline`, `LoggerInterface`) won't change. The option keys
documented in `docs/task-runner-quickstart.md` are stable; adding new
optional keys is minor.

### `Runner\TaskResultEnvelope` (Phase B)

All public-readonly properties listed in
`docs/task-runner-quickstart.md` are stable. Adding new properties
is minor (existing destructuring keeps working). Removing,
renaming, or changing the type of an existing property is major.

`::failed()` and `toArray()` signatures are stable.

### `AgentSpawn\Pipeline` (Phase C)

```php
class Pipeline
{
    public function __construct(
        CapabilityRegistry $caps,
        Dispatcher $dispatcher,
        EngineCatalog $catalog,
        ?LoggerInterface $logger = null,
        ?\Closure $orchestratorFactory = null,    // test seam
    );
    public function maybeRun(string $backend, string $outputDir, TaskResultEnvelope $firstPass, array $options = []): ?TaskResultEnvelope;
}
```

The `orchestratorFactory` test seam is stable but explicitly intended
for testing — production code should never pass it. The option keys
forwarded through `maybeRun()` to the consolidation `Dispatcher::dispatch()`
call are stable.

### `Contracts\BackendCapabilities` (Phase C addition + Phase E hardening)

The interface itself is stable. Phase E ships
`Capabilities\Concerns\BackendCapabilitiesDefaults` so future minor
releases can add new methods without breaking host implementations
that adopt the trait. **Hosts implementing custom Capabilities should
`use BackendCapabilitiesDefaults;`** to inherit no-op defaults for
post-Phase-E additions.

```php
class HostCustomCapabilities implements \SuperAICore\Contracts\BackendCapabilities
{
    use \SuperAICore\Capabilities\Concerns\BackendCapabilitiesDefaults;

    // Implement only the methods you need; trait handles the rest.
    public function key(): string { return 'host_custom'; }
    public function toolNameMap(): array { return []; }
    public function supportsSubAgents(): bool { return false; }
    public function supportsMcp(): bool { return false; }
    public function streamFormat(): string { return 'text'; }
    public function mcpConfigPath(): ?string { return null; }
    public function transformPrompt(string $prompt): string { return $prompt; }
    public function renderMcpConfig(array $servers): string { return ''; }
}
```

### `Capabilities\SpawnConsolidationPrompt::build()` (Phase C)

```php
final class SpawnConsolidationPrompt
{
    public static function build(SpawnPlan $plan, array $report, string $outputDir): string;
}
```

The signature is stable. The output **string contents** (the actual
prompt text + the `摘要.md` / `思维导图.md` / `流程图.md` filename
convention) are NOT stable — the maintainer reserves the right to
tune the consolidation instructions in any release. Hosts that depend
on those exact filenames being baked in should NOT extend this
class — instead, build their own consolidation prompt and feed it
directly into `TaskRunner::run()` as a separate dispatch.

### `Services\Dispatcher::dispatch()` (Phase A + D additions)

The option keys, the return envelope shape, and the auto-key
derivation rule from Phase D
(`{backend}:{external_label}` when `external_label` set, otherwise
no auto-key, `false` opts out) are stable.

### `Services\UsageRecorder::record()` (Phase D addition)

The input shape and the `idempotency_key` semantics are stable. The
60s window default is stable; if it changes in a future release the
maintainer will bump major.

### `Services\EloquentUsageRepository::IDEMPOTENCY_WINDOW_SECONDS` (Phase D)

The constant value (60) is stable. Hosts can read it at runtime to
compute "how long until this row's dedup window closes" without
hardcoding the magic number.

### `Models\AiUsageLog` columns

All columns documented in the migrations (and projected through
`fillable`) are stable. New columns may be added in minor releases;
existing columns won't be removed or renamed at minor.

---

## Intentionally NOT stable (subject to change at minor)

- **Internal classes** under `src/Backends/` (concrete CLI backend
  implementations): public methods are reachable but their signatures
  (e.g. `parseJson`, `parseJsonl`, `parseStreamJson`, `parseOutput`,
  protected helpers) may evolve as upstream CLIs change their output
  formats. Use `CliOutputParser` static delegates instead.
- **`Runner\AgentRunner`** and per-engine subclasses
  (`ClaudeAgentRunner`, `KiroAgentRunner`, etc.) — these are an older
  API surface that pre-dates `TaskRunner`. They keep working, but
  the maintainer doesn't commit to their long-term shape. Prefer
  `TaskRunner` for new code.
- **`AgentSpawn\Orchestrator`** — usable directly but `Pipeline` is
  the recommended entry point. Orchestrator's signature may evolve
  if `Pipeline` needs richer hooks.
- **`AgentSpawn\ChildRunner`** + per-engine implementations
  (`CodexChildRunner`, `GeminiChildRunner`) — interface may grow.
  Hosts adding a new CLI's ChildRunner should be prepared for one
  signature change at minor.
- **Blade templates / view files** — UI is iterated on continuously.
  Hosts that override package views accept the churn.
- **CLI commands** (`super-ai-core:*` artisan commands) — argument
  shapes and output formats may change at minor.
- **Database column types and indexes** beyond what's documented in
  the model `@property` block are internal — adding/removing indexes
  to optimize query patterns can happen at minor.

---

## Deprecation policy

When a stable API needs to change in a way that requires breaking it:

1. The new API ships in minor release N alongside the old one. The
   old API is marked `@deprecated` in PHPDoc with a pointer to the
   replacement.
2. Both APIs coexist for at least two minor releases (N+1, N+2). The
   `@deprecated` tag stays.
3. The next major release (N+3 or later) removes the old API.

Hosts get at least two minor cycles to migrate. Migration paths are
documented in the CHANGELOG entry that introduces the deprecation
and again in the major release that removes the old API.

---

## What "stable" doesn't promise

- Bug-for-bug compatibility — if an existing API has a bug, fixing
  it isn't a breaking change even if some host depended on the
  buggy behavior.
- Behavior of any internal class (anything not listed under "Stable
  APIs" above).
- Performance characteristics — query patterns, allocation counts,
  network call counts, log volume may change at minor.
- The exact text of log messages, exception messages, or model-facing
  prompts (these are tuned across releases).
- Backward compatibility with hosts that violate documented contracts
  (e.g. mutating readonly properties via Reflection).

---

## Reporting an unintended break

If you upgrade a minor release and a stable API breaks, that's a bug
in SuperAICore — file an issue with:

- The minor versions you went from / to.
- The exact API call that changed.
- The host code (or repro snippet) that worked before and doesn't
  now.

The maintainer will fix it in the next patch. Documented breaking
changes only happen at major bumps.
