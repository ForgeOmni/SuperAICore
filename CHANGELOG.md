# Changelog

What each release of `forgeomni/superaicore` means for you — new abilities, problems solved, and anything you need to do to upgrade. Written from the user's perspective; the full engineering detail (class-level changes, config keys, internals) lives in [CHANGELOG-TECHNICAL.md](CHANGELOG-TECHNICAL.md).

Follows [Semantic Versioning](https://semver.org). Unless an entry says otherwise, upgrading is just `composer update forgeomni/superaicore` — no migrations, nothing breaks.

## [1.1.7] — 2026-07-17

**Kimi K3 — Moonshot's new open-weight flagship is priced and ready to route.** SuperAgent SDK pin moves to `^1.1.7`; no migrations, nothing breaks. Re-publish the config if you want the new pricing row.

- **Kimi K3 priced and pickable** — `kimi-k3` (released 2026-07-16, a 2.8T open-weight model with a 1M-token context and always-on thinking) is the SDK's new zero-config Kimi default, at the official **$3 in / $0.30 cached / $15 out** per million. Your metered-Moonshot cost rows now bucket it correctly offline, no catalog round-trip. The coding-focused `kimi-k2.7-code` stays exactly where it was; the older `kimi-k2-6` remains reachable by id.
- **Fixed: the CLI now reports the right version.** `superaicore --version` was stuck at `1.1.5` (it wasn't bumped in the 1.1.6 release); it now reads `1.1.7`.
- **Grok CLI support hardened** (verified against `grok` 0.2.102):
  - **Fixed a dispatch failure on high effort** — sending `effort: max` (or `xhigh`) to the Grok CLI used to pass `--effort max`, which grok-4.5's three-level dial *rejects*, failing the whole run. Those values now clamp to `high`, and `off`/`none`/`minimal` send nothing instead of erroring.
  - **Richer Grok results** — Grok CLI runs now surface `session_id`, turn count, cache-read tokens and the model's reasoning (`thinking`) in the result envelope, matching the other CLI engines. (Subscription billing is unchanged — Grok CLI stays $0/token.)
  - **Resume a Grok session** — pass `resume_session_id` (or `continue_session` / `fork_session`) to continue a prior Grok conversation, the same way Claude and Codex already work; `superaicore resume` picks it up.
- **Reliability hardening** (a bug sweep across routing, cost tracking and dispatch — all with new regression tests):
  - `grok-composer-2.5-fast` now routes to the Grok engine instead of being misdirected to Cursor (it contains the word "composer").
  - Cost dashboards stop dropping the prompt-cache slice when a provider reports it under the legacy field name, and `qwen3-coder-next` is now priced (was billing $0).
  - A quota / rate-limit failure on a **streaming** run now cools down the affected account so the next task rotates to a healthy one (it already did for non-streaming runs).
  - Fixed a crash when an engine is misconfigured to a non-array value, a Cursor streaming edge case that could return an empty result, a fallback path that didn't kick in on silent crashes, and a process-monitor false "dead" for jobs running under another user. Laravel's `bootstrap/cache` writes no longer count as task side-effects.
- Housekeeping otherwise — an internal cleanup of the dispatch-option forwarding in `SuperAgentBackend` (no behavior change) and its regression tests.

## [1.1.6] — 2026-07-12

**GPT-5.6, Grok 4.5 and a price-table truth-up — your cost dashboards match what vendors actually bill.** SuperAgent SDK pin moves to `^1.1.6`; no migrations. Re-publish the config if you want the refreshed pricing table.

- **New models priced and pickable** — GPT-5.6 Sol/Terra/Luna, Grok 4.5, Gemini 3.5 Flash (the real flagship), Gemini 3.1 Pro/Flash-Lite, Kimi K2.7 Code and the GLM turbo pair, all at official rates with cached-input tiers.
- **Stale prices corrected** — `gpt-5` was billed at a $5/$15 estimate, it's actually **$1.25/$10**; DeepSeek V4 Flash output halved to $0.28; MiniMax M3 halved to $0.30/$1.20; `qwen3.7-plus` halved to $0.40/$1.60. If your host published an older config copy, re-publish or your dashboards keep over-charging.
- **New request knobs just work** — GPT-5.6's `reasoning_mode: pro` / `reasoning_context` / explicit prompt caching, and Gemini's `thinking_level`, forward through the normal dispatch options; providers that don't speak them ignore them.
- **Defaults moved SDK-side** — zero-config `openai-responses` → `gpt-5.6-sol`, `grok` → `grok-4.5` (500K context — pin `grok-4.3` if you needed 1M), `gemini` → `gemini-3.5-flash`. Every old id stays reachable.
- **Subscription CLIs re-verified live** — Grok Build now routes `grok-4.5` (+ `grok-composer-2.5-fast`); Cursor Composer's picker gains Fable 5 / Sonnet 5 / GPT-5.6 Sol / Grok 4.5 / Gemini 3.5 Flash / Kimi K2.7 / GLM 5.2 with easy `fable`/`sonnet`/`grok`/`gemini`/`kimi`/`glm` aliases. ZCode (Z.ai's desktop IDE) was evaluated — no headless CLI yet, so nothing to integrate.
- Phantom models removed: `gemini-3.5-pro` / `gemini-3.5-flash-lite` never actually shipped and no longer appear in pickers.

## [1.1.5] — 2026-07-08

**The dispatch skill now reaches every agent CLI — and can be cleanly removed.**

- `superaicore skill:install-dispatch` covers **Grok, Cursor and Qwen** on top of Claude Code / Codex / Gemini. `--agent all` installs everywhere at once; the default remains Claude Code only.
- **`--uninstall`** reverses a prior install without touching any skill you authored yourself.

## [1.1.1] — 2026-07-06

**Windows hotfix.** The process dashboard crashed on Windows ("Call to undefined function posix_kill"). It now loads everywhere, and process alive/dead status is actually *correct* on Windows, not just non-crashing.

## [1.1.0] — 2026-07-05

**Send a task to any AI with one word — and it survives quota walls.**

- `superaicore send opus "review this diff"` — type a short name (`opus`, `fable`, `kimi`, `codex`, any model id or engine) and the task routes to the right engine automatically. If that engine hits a quota, rate limit, or auth problem, the task **falls through to the next best candidate** instead of dying — and the result tells you exactly which engine answered and why.
- **Resume a CLI conversation later** — `superaicore resume --session-id <id> "follow-up"` continues the same Claude or Codex session, never silently switching engines on you.
- **Browse your past runs** — every send/resume is archived; `superaicore runs list` shows them, no database needed.
- **Teach agents your preferences** — a plain-text preferences file ("use kimi for Chinese content, opus for hard reviews") that any calling agent reads before picking a target.
- **`superaicore doctor`** — one command tells you what can dispatch right now: which CLIs are installed, logged in, and routable.
- **Claude 5 models everywhere** — Fable 5 and Sonnet 5 now appear in every model picker; retired Opus 4.6 rows removed.

## [1.0.11] — 2026-07-03

**Claude Fable 5 and Sonnet 5 arrive — and Opus gets 3× cheaper.**

- Fable 5 (Anthropic's most capable model, 1M context) and Sonnet 5 are available on the SuperAgent path with correct pricing.
- Opus pricing corrected to the official **$5/$25** per million (was shown at the stale $15/$75) — your cost dashboards now reflect what Anthropic actually bills.
- Reliability: Kiro-related tests no longer depend on whatever is installed on the developer's machine.

## [1.0.10] — 2026-06-18

**GLM-5.2 support.** Z.ai's coding flagship (1M context) is now available with accurate cost tracking, plus a reasoning-effort dial on GLM models.

## [1.0.9] — 2026-06-05

**Fix: chat with MCP tools actually works.** 1.0.8's per-chat MCP feature had a blocker — the model could see none of the tools you gave it (current Claude CLIs hide MCP tools behind a search step that our tool allowlist was accidentally locking out). Now the tools are reachable as intended.

## [1.0.8] — 2026-06-05

**Chat turns can now use MCP tools.** A chat feature in your app can hand a specific set of MCP servers to a single conversation turn — "chat with these tools" — running on your Claude subscription login rather than requiring an API key. Default behavior unchanged: no tools unless you ask.

## [1.0.7] — 2026-06-04

**MiniMax M3 support + a DeepSeek price correction in your favor.**

- MiniMax M3 (1M context, image & video input, interleaved thinking) is available with correct pricing.
- DeepSeek V4 Pro repriced to the live rate — it's cheaper than the stale figure your dashboard was showing ($0.435 in / $0.87 out vs $0.55 / $2.20), and cache-hit input is now accounted separately.

## [1.0.6] — 2026-06-03

**Your skill library follows you to every CLI — automatically and safely.**

- One command (`superaicore:sync-cli`) publishes your skills and agents to every installed CLI (Codex, Gemini, Copilot, Cursor, Grok, Kimi, Kiro, Qwen) in their native formats. Better: every dispatch checks freshness automatically, so synced CLIs never run stale skills.
- **Safety fix**: the sync can no longer overwrite your source skills through a symlink — the failure mode that once clobbered 72 skill files is structurally impossible now.
- **Login fix**: running on a subscription login (Claude/Codex/Gemini/Cursor/Grok) no longer fails with 401 when a stale API key is lying around in the environment — stale keys are scrubbed before spawn.

## [1.0.5] — 2026-06-02

**SmartFlow — author a multi-AI workflow once, run it forever.**

- Define a deterministic pipeline (plan → parallel fan-out → quality gates → synthesis) in YAML or code, with each step pinned to whichever CLI is best or cheapest for it — e.g. plan on Claude, build on Codex, review on Gemini, all in one flow.
- **Resume without paying twice** — every run keeps a ledger; re-running replays finished steps for free and only pays for what changed.
- **Rehearse for free** — `--rehearse` walks the whole graph with stub outputs and zero AI calls, so you validate a flow before spending a cent.
- **Budget caps, real parallelism, structured outputs** that never crash on a model's messy reply, and a council/gate vocabulary for adversarial checks.
- Ships 4 ready-made flows (review / dev / council / federated) and can delegate sub-flows to the SDK's cross-model engine.

## [1.0.2] — 2026-05-31

**Kimi keeps working through Moonshot's CLI transition — and streaming costs stop reading zero.**

- Moonshot replaced their `kimi` CLI with an incompatible rewrite; SuperAICore now detects which one you have and speaks to it correctly. No configuration change needed.
- Fixed (via SDK): streamed responses from Kimi/Qwen/GLM/MiniMax/DeepSeek/Grok/OpenRouter/OpenAI were recording **zero tokens and zero cost** — accounting is restored. Tool calling against Moonshot's strict validator also fixed.

## [1.0.1] — 2026-05-29

**Clearer diagnostics.** When a provider has no API key configured, the health check now says exactly that (naming the env var) instead of a generic SDK message. Minor test-infrastructure cleanup.

## [1.0.0] — 2026-05-28

**First stable release — the public API is now a promise.**

- SemVer stability contract: what's stable stays stable through the 1.x line ([docs/api-stability.md](docs/api-stability.md)).
- **Claude Opus 4.8** becomes the flagship — expert-tier work routes to it automatically.
- **xAI Grok API** provider (metered, `grok-4.3`, 1M context).
- Two new subscription CLI engines: **Cursor Composer** and **Grok Build** — the engine matrix reaches 10. They appear in your providers page, model pickers, and process monitor automatically.

## [0.9.9] — 2026-05-23

**Stability follow-up.** Fixed a crash that could take down the whole app when the Qwen backend was enabled, plus Windows fixes inherited from the SDK (argument parsing, terminal detection).

## [0.9.8] — 2026-05-22

**Use SuperAICore from any OpenAI-compatible app — plus an eighth engine and a black-box flight recorder.**

- **OpenAI-compatible endpoint**: point Cursor, Cline, Roo, continue.dev, or any OpenAI SDK at `/super-ai-core/v1/chat/completions` and they talk to *all* your engines through one URL, streaming included.
- **Qwen Code CLI** joins as the 8th engine (`qwen3.7-max`, 1M context, drop-in Claude-protocol compatible).
- **Flight recorder**: when a dispatch fails, times out, or hits quota, a trace of what happened is saved automatically — open it in Chrome's tracing UI and see the whole story. Zero overhead when nothing goes wrong.
- **Multi-account rotation with cooldowns** — add several accounts per provider; quota-limited ones rest while others take traffic.
- **Named routing combos** — save ordered provider/model chains ("try this, then that") and reference them by name anywhere, including from the OpenAI endpoint.
- **Session branching** — fork a conversation at any message, explore, then switch back; the abandoned path is auto-summarized so nothing is lost.
- **GitHub PR watcher** — react to new comments, failed CI, or requested changes by asking you, spawning a squad, or hitting a webhook.
- **OAuth auto-refresh** — Claude/Codex/Copilot/Kiro tokens refresh before they expire, so long-running jobs stop dying at the TTL boundary.
- Codex and Gemini can now discover and use your whole skill catalog (a compact index is provided; they read full skills on demand).
- Also: an agents browser page, a cost-savings dashboard (output compression + Arrow), token-saving "caveman mode", and a session JSONL exporter.

Upgrade: `php artisan migrate` (4 new tables + 1 column).

## [0.9.7] — 2026-05-20

**See what every run changed — and take back control mid-run.**

- **Per-file diff on every dispatch**: the usage page shows a `+N −M` badge per run with a side-panel diff viewer, and a **one-click revert** restores your worktree to the pre-run snapshot.
- **The agent can ask YOU questions mid-run** — instead of guessing, a model can pause, put a question on your processes page (with buttons or free text), and continue with your answer.
- **Plan mode**: plan → you approve → build. The model writes a plan with editing disabled; nothing changes until you say yes.
- **Per-agent permissions**: declare what each agent may do (last-matching-rule wins); sub-agents can never exceed their parent's permissions.
- **Long-lived shell sessions** and **session sharing** (mint a link to a run's history), snapshot retention pruning, config-driven session reminders, and a real LSP tool (diagnostics/hover/definition across 9 language servers).
- Smarter context compaction that preserves your prompt cache instead of clobbering it every round.

Upgrade: `php artisan migrate` (3 new tables + 3 columns).

## [0.9.6] — 2026-05-16

**Squad — a team of models works your task, each step on the right-priced brain.**

- Tasks decompose into steps classified trivial → expert; each tier maps to an appropriate model (Haiku for trivial, Opus for expert — fully configurable). Steps checkpoint to disk, so a mid-run failure resumes instead of restarting, and an optional cost cap automatically downshifts tiers near budget.
- **Auto model routing**: opt a CLI into `auto` and it escalates Flash → Pro on long context, deep tool chains, or review/audit-type intent — cheap by default, smart when it matters.
- Also: per-provider rate limiting, conversation side-branches ("try a different model, keep only the good messages"), per-session scratch memory, DeepSeek fill-in-the-middle for IDE-style completion, and a sub-agent recursion cap.

## [0.9.5] — 2026-05-11

**Dashboard fix.** Screenshot badges and metadata inspectors on the processes/usage pages opened with broken or empty panels when the underlying data contained quotes or HTML. Now they render correctly on every row.

## [0.9.2] — 2026-05-05

**Long tasks survive quota walls.** When a CLI or API hits its usage limit mid-task, the task hands off to the next backend in a chain you control — carrying a summary of what happened so the new engine continues rather than restarts.

- Chains can differ per workload (coding vs research vs summarise), come with sane presets, or build themselves from whatever's installed.
- Only genuine limit-type failures trigger handoff — real errors still stop and tell you.
- Guards everywhere: max attempts, max cost, backoff, per-backend cooldowns after repeated failures, and a "reject empty/boilerplate answers" quality check.
- Every run reports which backends were tried and why — your UI can badge "continued on codex" without log spelunking.
- The recovered primary automatically takes traffic again next run; no sticky failover state.

## [0.9.1] — 2026-05-04

**Know your cache savings — and gate what agents may execute.**

- **Cache hit rate on every usage row and dashboard**: "what fraction of my paid prompt was free this period?" answered at a glance.
- **Approval gate** with three modes (Auto / Suggest / Never): read-only tools always flow; mutations can require a one-time approval, codex-style.
- **Durable goals** that survive restarts, a headless `/v1/usage` JSON API for your own dashboards, and a workspace plugin manifest so `git clone` tells new machines exactly which plugins the team expects.

Upgrade: `php artisan migrate` (1 new table).

## [0.9.0] — 2026-05-03

**DeepSeek V4 + a wave of operator comfort.**

- **DeepSeek V4** first-class (Pro $0.55/$2.20, Flash $0.14/$0.55 per M) — a cheap reasoning tier for heavy analytical workloads.
- **Add providers from scripts**: `provider:add` (secret-safe via stdin) for CI and container bootstrap; `provider:rotate` swaps accounts when quota hits, optionally automatically.
- **Cache-cold warnings**: when a session is about to lose its Anthropic prompt-cache discount, the usage page badges the call (`❄ cold`) and counts them — actionable money on the table.
- **See what browser agents see**: runs that take screenshots show the latest frame right on the processes page.
- **Mermaid diagrams render live** in dashboards and streamed output; a right-hand side panel shows rich payloads (JSON, diagrams, iframes) anywhere.
- **Resume sessions from Claude Code or Codex** — pick a past session from either harness and load its transcript (off by default on shared machines).
- Skill search gets a semantic second pass (better ranking beyond keywords), usage rows carry a user/ambient source split, and deprecated models warn you with a "migrate to X by DATE" note.

## [0.8.9] — 2026-04-28

**Fix: SDK-routed providers no longer show "not installed".** Providers that run in-process (MiniMax/Qwen/GLM/OpenRouter/Kimi-direct/LM Studio via SuperAgent) were being blocked by a CLI-binary check they can never pass. A proper installed-check now exists for gating.

## [0.8.8] — 2026-04-28

**Windows: long prompts work.** Prompts over ~8K characters were silently truncated or failed to start on Windows (cmd.exe's command-line cap). Stdin-capable CLIs (Claude/Codex/Gemini) now receive the prompt via stdin; argv-only CLIs (Kimi/Kiro/Copilot) route around the cap automatically. Also removes a whole class of quoting bugs for prompts with quotes, newlines, or CJK text — on every platform.

## [0.8.7] — 2026-04-28

**Windows: CLI detection and login status work.** Installed CLIs appeared missing and logged-in CLIs showed "not signed in" on Windows (a POSIX-ism in the probe commands). Detection, auth status, and binary lookup now work across Windows/macOS/Linux, including Scoop/Chocolatey/npm-global install locations.

## [0.8.6] — 2026-04-27

**Your skills get memory, search, and a self-repair loop.**

- **Every skill run is tracked automatically** — which skills run, how often they succeed or fail, how long they take. `skill:stats` shows the table; failure rates are color-coded so degrading skills stand out.
- **Find the right skill by describing the task** — `skill:rank "audit my pricing page"` searches your whole library (English and Chinese both work) and ranks by relevance, boosted by each skill's real track record.
- **Degrading skills propose their own fixes** — when a skill's failure rate crosses a threshold, the system drafts a minimal patch from the actual failure evidence and queues it for **your review** (`skill:candidates`). Nothing is ever applied automatically; you stay the editor.
- Hooks wire into Claude Code with two commands so tracking is invisible once set up.

Upgrade: `php artisan migrate` (2 new tables).

## [0.8.5] — 2026-04-25

**Critical fix: the in-process SuperAgent backend was silently dead since 0.8.1.** A one-character typo made every call fail quietly (return null) instead of erroring. Fixed — and the SDK bump also fixes multi-turn tool use against non-Anthropic providers (Kimi/GLM/MiniMax/Qwen/OpenAI/OpenRouter), where replayed tool calls were being sent as nulls.

## [0.8.2] — 2026-04-25

**Providers page consistency.** The bottom half of an engine card now grays out and explains itself ("CLI not installed") the same way the top half does — no more contradictory signals on one card.

## [0.8.1] — 2026-04-25

**Your `.mcp.json` survives relocation.** Opt in and generated MCP configs use bare commands + `${YOUR_ROOT}`-relative paths instead of machine-specific absolute paths — copy the project to another machine, user, or container and everything still works. Also: the providers page stops offering toggles and "Built-in" rows for engines whose CLI isn't installed — no more controls that can't help you.

## [0.8.0] — 2026-04-24

**New CLI engines appear in your app automatically.** Host apps used to patch three code paths every time an engine was added. Now the engine ships everything itself — spawn behavior, chat behavior, auth traits — and your task-create pickers, chat targets, and process monitor pick it up with zero host changes. (Consolidates 0.7.1/0.7.2.)

## [0.7.2] — 2026-04-23

**Fewer host special-cases for engine auth.** Engines now declare "I have built-in login" and "my login status can be probed" as data — host apps read the flags instead of hardcoding per-engine exceptions.

## [0.7.1] — 2026-04-23

**Foundation for auto-discovered engines.** The spawn/chat contract that lets a new CLI engine slot into every host code path without host patches (completed in 0.8.0).

## [0.7.0] — 2026-04-23

**Two new ways to run models — and errors that explain themselves.**

- **OpenAI Responses API** provider: metered via API key, or route through a **ChatGPT Plus/Pro subscription** by storing an OAuth token. Azure OpenAI auto-detected.
- **LM Studio** provider: point at a local model server — no API key, no cloud.
- Provider failures now classify themselves (quota vs context-window vs policy vs overload…) so logs and routing can react to the *kind* of failure.
- Custom HTTP headers per provider type (project headers, observability tags) via config; W3C trace headers pass through for distributed tracing; duplicate usage rows deduplicate reliably across processes.
- Fix: `openai-compatible` providers without an explicit provider mapping were silently routed to Anthropic and failing — they now route where they were meant to.

## [0.6.9] — 2026-04-23

**Kimi and Qwen get real.** Kimi thinking-mode calls stop 400ing (the SDK was sending a made-up model id); Qwen switches to the OpenAI-compatible endpoint Alibaba's own tooling uses; `super-ai-core:models refresh` pulls each provider's live model list so new models appear without a package update; MCP servers that need OAuth get a device-flow login; opt-in loop detection catches an agent stuck repeating itself; parallel workers stop racing each other's Anthropic OAuth token refresh.

## [0.6.8] — 2026-04-22

**One command syncs MCP everywhere — and agent teamwork gets guardrails.**

- **`claude:mcp-sync`**: edit one catalog + one host mapping, run one command, and the project `.mcp.json`, every agent's server list, and every installed CLI's own config all match. Ends the "trimmed the project config but Gemini still spawns 50 dead servers" class of bugs. `--dry-run` previews; your hand-edits are detected and respected.
- **Real agentic runs in-process**: the SuperAgent backend now honors multi-turn loops, a hard budget cap, tool filters, and your project's MCP config.
- **`api:status`**: one table tells you, per API provider, whether the key works, auth is rejected, or the network timed out — no more guessing which of the three it is.
- **Weak-model cleanup crew**: multi-agent fan-out runs now audit each agent's output (wrong files, stolen roles, wrong language), keep plumbing files out of your deliverables folder, enforce consistent Chinese/English output, and stop a fast-but-sloppy model from breaking consolidation.

## [0.6.7] — 2026-04-22

**Claude runs reliably from web servers.** Two blockers fixed: spawned Claude processes inherited markers from a parent Claude session and refused to authenticate; and on macOS, web workers couldn't read the Keychain login (now bridged automatically). Plus the Process Monitor shows only *actually running* processes — finished runs disappear on exit instead of piling up.

## [0.6.6] — 2026-04-21

**One call replaces 200 lines of task plumbing.**

- **TaskRunner**: `run($backend, $prompt, $options)` handles spawn, live streaming, log tee, output parsing, usage recording, and returns one typed result. The 100–200 lines of glue every host wrote is gone.
- **Live streaming** from all five CLI engines, with your UI updating chunk by chunk.
- **Multi-agent fan-out pipeline**: first pass plans, N children run in parallel, a consolidation pass merges — all inside the package now.
- **Duplicate usage rows auto-collapse**: hosts that accidentally record the same turn twice get one row, automatically.
- A formal API stability document tells you exactly which surfaces are safe to build on.

Upgrade: `php artisan migrate` (1 new column).

## [0.6.5] — 2026-04-21

**Costs stop being ~10× wrong on cache-heavy runs.** Cache reads are priced at their real ~10% rate (writes at 125%) instead of full input price, and when the Claude CLI reports its own billed cost, that figure wins. Also: Kiro login detection fixed, and the usage table gains Provider and Capability columns so "which key ran this?" has an answer.

## [0.6.2] — 2026-04-21

**Your dashboards stop looking empty.**

- **Shadow cost**: subscription-billed engines (Copilot, Claude builtin) now show "what this would have cost pay-as-you-go" — real numbers instead of $0 rows.
- **One-liner usage recording** for host apps that spawn CLIs themselves; noise (0-token rows, test-connection clicks) is filtered by default.
- **Kiro fixes**: your model selection was being silently ignored (every call went to Kiro's auto-router) — fixed; the model picker is now populated live from the CLI and shows all 12 models including DeepSeek/MiniMax/GLM/Qwen.
- Provider-type metadata becomes one registry — host apps delete their duplicated tables and env-var switches, and future provider types appear with just a `composer update`.

Upgrade: `php artisan migrate` (2 new columns).

## [0.6.1] — 2026-04-20

**AWS Kiro joins as the sixth engine** — with the richest feature set of any CLI: native agents, skills (reads Claude's SKILL.md format verbatim), MCP, and native sub-agent orchestration. Two login paths (browser login or headless API key). Subscription-billed; per-call credits surface for your dashboards.

## [0.6.0] — 2026-04-19

**Model pickers and pricing stay current by themselves.** Pricing, model aliases, and dropdown contents now flow from a live model catalog — new models and rate changes arrive with `models update` (or opt-in auto-refresh), no package update needed. Gemini's OAuth login status finally shows on the providers page.

## [0.5.9] — 2026-04-19

**Two fixes and one cleanup**: the Copilot card actually renders on the providers page; login detection works under `php artisan serve` and env-stripped FPM (everything showed "not signed in" before); and model dropdowns come from one catalog call instead of hand-rolled per-backend lists in every controller.

## [0.5.8] — 2026-04-18

**CLI runs report real token usage — no more $0 rows.** Claude/Codex/Gemini switch to structured output and real token counts land in your usage dashboard. Plus: `cli:install` bootstraps missing CLI binaries; `copilot:fleet` fans a task out to N Copilot agents in parallel; and your Claude-style hooks sync into Copilot.

## [0.5.7] — 2026-04-18

**GitHub Copilot joins as the fifth engine.** Full pipeline: run prompts, skills, and agents through Copilot; your `.claude/agents` translate automatically; tool permissions map to Copilot's grant syntax. The cost dashboard learns the difference between per-token billing and subscription billing so Copilot doesn't pollute your USD totals.

## [0.5.6] — 2026-04-17

**Run your Claude skills on Codex and Gemini.** `skill:run <name> --backend=gemini` translates tool names, injects the right guidance, and runs — with a compatibility probe that warns before you waste a run, an automatic **fallback chain** (if Gemini fails cleanly, Claude picks it up — with a hard lock against double-writes), typed skill arguments, and `gemini:sync` making every skill/agent available as a native Gemini slash-command. The binary is renamed `superaicore`.

## [0.5.5] — 2026-04-17

**Cross-engine compatibility layer.** The foundation for running one skill library on many engines: per-engine capability adapters, one canonical MCP list synced to every CLI's native config, and a spawn-plan protocol that gives sub-agent-less CLIs (Codex/Gemini) a working multi-agent story. Verified on a real workload: a Gemini run that used to navel-gaze the local codebase now actually researches the web.

## [0.5.2] — 2026-04-17

**Gemini joins as the fourth engine** — both the CLI (OAuth or API key or Vertex) and direct HTTP API, with model aliases (`pro`/`flash`) and pricing.

## [0.5.1] — 2026-04-17

**Table prefixes + CI.** Package tables get a configurable `sac_` prefix so they can't collide with yours; a real test suite and a 9-way CI matrix land. **Breaking (pre-1.0):** existing 0.5.0 installs must either set `AI_CORE_TABLE_PREFIX=''` or rename tables.

## [0.5.0] — 2026-04-16

**Initial release.** The AI execution stack as one Laravel package: three engines (Claude CLI, Codex CLI, SuperAgent SDK) plus Anthropic/OpenAI HTTP backends, a unified dispatcher with provider/model routing, usage + cost tracking, MCP server management, a live process monitor, and a trilingual (EN/中文/FR) admin UI for providers, services, usage, and costs.
