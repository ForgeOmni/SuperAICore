# 02 — Prompt caching (Anthropic 5-minute TTL)

Goal: drive 5 calls into the same Anthropic session, see the cache reads
accumulate, and understand the cache-cold warning.

## Why this matters

Anthropic prompt cache has a 5-minute TTL. Each call inside that window with
the same prefix gets 90% off the input price. Drop out of the window for one
call and you pay full price for the whole prefix again.

SuperAICore Dispatcher emits a `cache_warning: 'cache_likely_cold'` envelope
flag when the next call to the same `session_id` arrives ≥ 270s after the
previous one (default — `super-ai-core.cache_cold_warning.threshold_seconds`).

## Code

```php
use SuperAICore\Services\Dispatcher;

$dispatcher = app(Dispatcher::class);

$systemPrompt = file_get_contents(__DIR__ . '/fixtures/long-system-prompt.md');  // ~4000 tokens

for ($i = 1; $i <= 5; $i++) {
    $r = $dispatcher->dispatch([
        'system'    => $systemPrompt,                  // KEEP IDENTICAL across calls
        'prompt'    => "Question {$i}: What's interesting about <topic-{$i}>?",
        'max_tokens'=> 200,
        'metadata'  => [
            'session_id' => 'cache-demo',              // KEEP IDENTICAL across calls
        ],
    ]);

    printf(
        "Turn %d  cache_read=%d  input=%d  cost=$%.4f  warn=%s\n",
        $i,
        $r['usage']['cache_read_input_tokens'] ?? 0,
        $r['usage']['input_tokens'],
        $r['cost_usd'],
        $r['cache_warning'] ?? '-',
    );
}
```

Expected output (first call writes the cache, calls 2-5 read it):

```
Turn 1  cache_read=0     input=4012  cost=$0.0120  warn=-
Turn 2  cache_read=4012  input=18    cost=$0.0016  warn=-
Turn 3  cache_read=4012  input=20    cost=$0.0016  warn=-
Turn 4  cache_read=4012  input=15    cost=$0.0016  warn=-
Turn 5  cache_read=4012  input=22    cost=$0.0016  warn=-
```

## When the cache misses

If you `sleep(300)` between calls 3 and 4, the next dispatch will see:

```
Turn 4  cache_read=0  input=4030  cost=$0.0120  warn=cache_likely_cold
```

Total cost for 5 turns jumps from $0.018 to $0.030 — the cache cold flag is
the operator's signal that the session's prefix is no longer being amortized.

## Authoring rules

From `.claude/skills/CONVENTIONS.md` §12 (SuperTeam):

1. **Stable prefix order** — keep `system` + first user turn byte-identical
2. **No timestamps / random ids in the early prompt** — push to last turn
3. **Pass `metadata.session_id`** — the heuristic needs a session key
4. **Group multi-step skills inside a 270s window**
5. **Tag long-idle dispatches as ambient** — `usage_source: 'ambient'`

## See also

- 01 — Dispatcher basics
- 05 — Tracing quickstart (cache-cold appears as `llm.cache_cold` event)
