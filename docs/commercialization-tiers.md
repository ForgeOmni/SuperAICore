# Commercialization tiers

> Reference / future-state document. SuperAICore is MIT-licensed and shipped
> as a complete open-source package today. This doc captures how a tiered
> offering could look if hosting demand grows — borrowed in spirit from
> Goldman's `gs-quant` (open SDK + gated backend) and JPMorgan's Perspective
> (open engine + FINOS governance + commercial overlays).

## Current state (2026)

| Component | Form | License | Cost |
|---|---|---|---|
| `forgeomni/superaicore` package | Composer dependency | MIT | $0 |
| Backends (Claude / Codex / Gemini / Copilot / Kiro / Kimi / Anthropic API / OpenAI API / SuperAgent in-proc) | Wired by package | MIT (wrapper) | Per-call API/CLI cost |
| Process Monitor, Cost Analytics, MCP Manager, Trace Viewer UIs | Blade views in package | MIT | $0 |
| Provider auto-rotation, prompt caching heuristic, idempotency | Built-in | MIT | $0 |

This is the whole product today. Self-hosted, single binary install, no
phone-home. The remainder of this document describes a possible tier
expansion — **none of it is implemented**.

## Proposed tier model (gs-quant pattern)

### Tier 1 — Core SDK (current, free forever)

The package as it ships today. Solo developers, small teams, hobby projects.

- Composer install + `php artisan vendor:publish`
- All backends, all UIs, all observability
- No usage tracking phone-home, no telemetry
- Community support via GitHub issues

### Tier 2 — Cloud Dashboard (proposed, hosted)

The SuperAICore Blade UI hosted at `dashboard.superaicore.dev`, paired with
the local SDK. Single SSO from operator → tenant; the dashboard reads from
a tenant-scoped subset of `ai_usage_logs` rows the SDK pushes via webhook.

Value proposition:
- Cross-machine usage rollup (single operator running on laptop + CI + server)
- Anomaly alerts (cache cold rate spike, quota-error storm, model drift)
- Team views (operator + collaborator)
- Historical trace dump archive (vs. local-disk-only on Tier 1)

Pricing model: per-seat monthly, free below N usage logs/month.

### Tier 3 — Managed Dispatcher (proposed, fully hosted)

Replace the local-process Dispatcher with a hosted endpoint. Use case: hosts
that don't want to manage Anthropic / OpenAI / Gemini credentials directly,
or that want zero-config provider rotation across vendors.

- One API key → call any model
- Built-in load balancing across managed provider accounts
- Spend caps, role-based access control
- SOC 2 / compliance posture

Pricing: cost-plus on the underlying provider bills (transparent passthrough
+ infra margin).

### Tier 4 — Enterprise overlays (proposed, custom)

- BYOK (your KMS keys for traces at rest)
- VPC peering, single-tenant deployment
- Custom backend modules
- 24/7 support SLA

## What stays open-source forever

Per the `forgeomni` charter, these stay MIT and never move behind a paywall:

- The Dispatcher resolution chain (backend → provider → routing)
- The trace ring buffer + dump format (Wave 1)
- The Perspective viewer integration (Wave 2)
- The Arrow serializer (Wave 3)
- The cookbook (Wave 4)
- Every existing UI page in `resources/views/`
- The provider registration model + rotation logic

This invariant is enforced by `LICENSE` (MIT) and the public package being
the canonical artifact. Tier 2+ adds new endpoints / dashboards / SLAs on top
— never claws back capability from Tier 1.

## gs-quant lesson — what we'd avoid

`gs-quant` is open-source but practically unusable without Goldman client
credentials for the actual pricing/risk backend. The repo is mostly notebook
documentation; the engine is closed.

If SuperAICore ever offers a hosted backend, the local Dispatcher must remain
functional with operator-owned API keys forever — the hosted backend is a
convenience, not a requirement. Reviewers can keep this commitment in mind
when evaluating proposed code changes:

> Does this change reduce what works in Tier 1 without the operator's own
> credentials? If yes → reject; refactor so Tier 1 still works.

## When this document becomes implementation

This is a Day 0 company exploration document (per CLAUDE.md). Move sections
out of "proposed" into "current state" only when:

1. Tier 1 has reached ramen profitability via support contracts / consulting
2. Multiple Tier 2 design partners have committed to monthly billing
3. The infra cost of running the proposed tier is < 20% of expected ARR

Until those gates clear, this stays a reference. The current product is the
free SDK — and that's enough.
