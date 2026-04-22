# MCP Sync

Superaicore's MCP-sync layer keeps one source-of-truth catalog of MCP servers
fanned out consistently across:

- the project-scope `.mcp.json` (what `claude code` picks up in this repo)
- per-agent `mcpServers:` frontmatter blocks (tier-2 servers scoped to a single
  agent so the project baseline stays small)
- every installed CLI backend's user-scope config (`~/.claude.json`,
  `~/.codex/...`, `~/.gemini/...`, `~/.copilot/...`, `~/.kiro/...`)

Two commands cover the flow:

| Command | Role |
|---|---|
| `claude:mcp-sync` | Primary. Reads catalog + host map, writes `.mcp.json` + agent frontmatter, then propagates. |
| `mcp:sync-backends` | Standalone propagation only. For hand-edited `.mcp.json` or file-watcher auto-sync. |

Both ship as Symfony commands on `bin/superaicore` and as artisan commands when
the package is installed into a Laravel host.

## Catalog

Host-supplied JSON (default location: `.mcp-servers/mcp-catalog.json`).

```json
{
  "mcpServers": {
    "fetch":      { "command": "uvx", "args": ["mcp-server-fetch"] },
    "sqlite":     { "command": "uvx", "args": ["mcp-server-sqlite"] },
    "arxiv":      { "command": "node", "args": ["./servers/arxiv.mjs"] },
    "pubmed":     { "command": "uvx", "args": ["pubmed-mcp"], "env": { "NCBI_API_KEY": "${NCBI_API_KEY}" } }
  },
  "domains": {
    "research": ["arxiv", "pubmed"]
  }
}
```

- `type` defaults to `stdio` when omitted.
- `domains` is optional — it's a convenience grouping, not used by the
  writers directly.
- Every entry referenced by `project.servers` or `agents.assignments` (below)
  must exist here — unknown names throw at sync time (this is intentional, to
  catch typos instead of silently dropping servers).

## Host mapping

Default location: `.claude/mcp-host.json`. Override with `--host-config`.

```json
{
  "catalog": ".mcp-servers/mcp-catalog.json",
  "project": {
    "enabled": true,
    "path": ".mcp.json",
    "servers": ["fetch", "sqlite", "timezone"],
    "manifest": ".claude/.superaicore-mcp-project-manifest.json"
  },
  "agents": {
    "enabled": true,
    "dir": ".claude/agents",
    "manifest": ".claude/agents/.superaicore-mcp-manifest.json",
    "assignments": {
      "research-jordan": ["arxiv", "pubmed"]
    }
  }
}
```

Relative paths resolve against `--project-root` (defaults to CWD).

Both `project` and `agents` sections have an `enabled` toggle — disable to
leave a tier untouched. Disabling both is an error (nothing to do).

## Non-destructive contract

Both writers extend `AbstractManifestWriter`, which persists the sha256 of each
file we last wrote. On re-sync:

1. On-disk hash == rendered hash → `unchanged`.
2. On-disk hash != rendered hash, AND manifest records us writing the previous
   hash → user has edited; we leave it alone (`user-edited`).
3. First-time write, or on-disk still matches our last write → overwrite
   (`written`).

The agent writer has a narrower "ownership" window: we only own the bytes
strictly between the `# superaicore:mcp:begin` / `# superaicore:mcp:end`
markers inside the YAML frontmatter. Edits *outside* the markers are preserved;
edits *inside* the markers are flagged (`user_edited`) but still overwritten,
because the managed region belongs to this tool by design.

Agents absent from `assignments` are **never** touched — no file under
`.claude/agents/` is modified unless it's explicitly listed.

## Dry-run

`--dry-run` on both commands prints the +/- table without touching disk or the
manifest. Use it before the first real run or whenever the host mapping
changes materially.

## Propagation

After `claude:mcp-sync` finishes writing the project file, it calls
`McpManager::syncAllBackends()` to write the same server set into each
supported backend's native config (via each `BackendCapabilities::renderMcpConfig()`).

Reasons to bypass this step:

- `--no-propagate` on `claude:mcp-sync` — skip during scripted runs that will
  propagate later.
- The propagate phase is non-fatal: if it fails, the project + agent writes
  already succeeded. The command tells you to retry with
  `mcp:sync-backends`.

`mcp:sync-backends` is the manual entry point — use it when:

- `.mcp.json` was hand-edited (bypassing the host-mapping flow).
- A file-watcher or git-hook wants to re-sync on every `.mcp.json` change.
- A backend drifted (its own config was edited externally).

`--backends=claude,codex` narrows the propagation.

## Typical workflows

**First setup**

1. Drop a catalog at `.mcp-servers/mcp-catalog.json`.
2. Write `.claude/mcp-host.json` with the project + agent tiers.
3. `bin/superaicore claude:mcp-sync --dry-run` → review the plan.
4. `bin/superaicore claude:mcp-sync`.

**Adding a server**

1. Add the entry to the catalog.
2. Add its name to `project.servers` and/or `agents.assignments.<agent>`.
3. `bin/superaicore claude:mcp-sync`.

**Recovering a drifted backend**

```sh
bin/superaicore mcp:sync-backends --backends=gemini
```
