# 03 — Provider rotation (jcode `/account` style)

Goal: rotate the active provider on quota error, manually or automatically,
and observe the cost dashboard catch the swap.

## Manual rotation

```bash
# Active provider hit quota — flip to the next candidate.
php artisan provider:rotate superagent --reason=quota_exceeded

# Force a specific provider as the next active row (skips the walk).
php artisan provider:rotate claude --to=8 --reason=manual_swap --json

# Wrap-around: rotate even when active is already the last candidate.
php artisan provider:rotate codex --wrap
```

## Auto-rotate on quota error

```dotenv
# .env
AI_CORE_AUTO_ROTATE=true
AI_CORE_AUTO_ROTATE_WINDOW=60
AI_CORE_AUTO_ROTATE_THRESHOLD=2
```

When SuperAgentBackend catches a second `QuotaExceededException` for the same
backend within `window_seconds`, it fires `provider:rotate <backend> --reason=quota_exceeded`
automatically. The next dispatch uses the next active provider.

## Observability

Since Wave 1, every rotation also dumps the Dispatcher trace ring to disk:

```
storage/app/superaicore/traces/trace_superaicore_<session>_<unix-ms>_rotate.json
```

View the trace in `/super-ai-core/traces` (web UI), or open it in
`chrome://tracing` / `ui.perfetto.dev`. The rotation event shows up alongside
the LLM calls leading up to it — invaluable for "why did we rotate at 14:23?"
post-mortems.

## Code: rotate from PHP

```php
use SuperAICore\Services\Dispatcher;
use SuperAgent\Exceptions\Provider\QuotaExceededException;
use Illuminate\Support\Facades\Artisan;

$dispatcher = app(Dispatcher::class);

try {
    $r = $dispatcher->dispatch([...]);
} catch (QuotaExceededException $e) {
    // (Dispatcher already auto-dumped trace via SuperAgentBackend's handler)
    Artisan::call('provider:rotate', [
        'backend'    => 'superagent',
        '--reason'   => 'app-level fallback',
    ]);

    // Retry once on the new active provider
    $r = $dispatcher->dispatch([...]);
}
```

## Inspect what changed

```bash
php artisan tinker
> AiProvider::where('backend', 'superagent')->orderBy('sort_order')->get(['id', 'name', 'is_active', 'extra_config'])
```

The freshly-activated row carries:

```
extra_config.last_rotation_reason = "quota_exceeded"
extra_config.last_rotation_at     = "2026-05-22T14:23:01+00:00"
extra_config.last_rotation_from   = 7   // the id we rotated off
```

`/providers` renders these as a "rotated 5 min ago because: quota_exceeded"
badge.

## See also

- 01 — Dispatcher basics
- 05 — Tracing quickstart (where the rotation auto-dump lands)
