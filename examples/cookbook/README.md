# SuperAICore cookbook

Runnable, narrative-driven examples — the gs-quant pattern adapted to PHP /
Laravel hosts.

| # | File | Topic |
|---|---|---|
| 01 | [`01-dispatcher-basics.md`](./01-dispatcher-basics.md) | One dispatch call, resolution order, cost envelope |
| 02 | [`02-prompt-caching.md`](./02-prompt-caching.md) | Anthropic 5-min cache TTL, cache-cold warning |
| 03 | [`03-provider-rotation.md`](./03-provider-rotation.md) | Manual + auto rotation, jcode `/account` style |
| 04 | [`04-resume-from-jsonl.md`](./04-resume-from-jsonl.md) | Cross-harness session resume (Claude Code ↔ Codex) |
| 05 | [`05-tracing-quickstart.md`](./05-tracing-quickstart.md) | magic-trace ring buffer, dump triggers, viewers |

Run each as a sequence of `php artisan tinker` snippets or copy into a
controller / job. Every cookbook is self-contained — no shared setup beyond
the prerequisites listed at the top of file 01.

## Conventions

- Each file is one focused topic
- Includes prerequisites, copy-pasteable code, expected output, and a "Try
  this next" section
- Cross-references siblings via the `## See also` section
- Linked from the package README under "Examples"

## Adding a new cookbook

1. Number it (06, 07, …) so the order is stable
2. Open with a one-line goal statement
3. Show ONE concept end-to-end — split if it's two concepts
4. Close with `## See also` cross-references
