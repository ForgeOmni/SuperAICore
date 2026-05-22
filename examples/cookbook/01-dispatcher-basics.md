# 01 — Dispatcher basics

Goal: dispatch a single LLM call through SuperAICore, see the result envelope,
and understand the resolution order.

## Prerequisites

- A booted Laravel host with `super-ai-core` registered
- One active provider — easiest is `provider:add anthropic-dev --backend=superagent --type=anthropic --api-key-stdin --activate`
- `composer require forgeomni/superaicore`

## Code

```php
use SuperAICore\Services\Dispatcher;

$dispatcher = app(Dispatcher::class);

$result = $dispatcher->dispatch([
    'prompt' => 'In two sentences, what is the Anthropic prompt cache TTL and why does it matter?',
    'max_tokens' => 200,
    'metadata' => [
        'session_id' => 'cookbook-01',
    ],
]);

echo $result['text'] ?? '(null)';
echo "\n---\n";
echo "Model:    {$result['model']}\n";
echo "Backend:  {$result['backend']}\n";
echo "Tokens:   in={$result['usage']['input_tokens']}  out={$result['usage']['output_tokens']}\n";
echo "Cost:     \${$result['cost_usd']}  ({$result['billing_model']})\n";
echo "Duration: {$result['duration_ms']} ms\n";
```

## What happened

The Dispatcher walked this resolution order:

1. `options['backend']` — not set, skipped
2. `options['provider_id']` — not set, skipped
3. `options['task_type'] + ['capability']` → RoutingRepository → not set, skipped
4. Active provider for the global scope — picks the row from `provider:add ... --activate`
5. Fall back to `config('super-ai-core.default_backend')` (anthropic_api)

`result['cost_usd']` came either from the provider's own envelope (`usage.total_cost_usd`)
or from `CostCalculator` using the catalog in `config/super-ai-core.php`.

## Try this next

- Add `'stream' => true` and a `'onChunk' => fn($c) => echo $c` — see live tee
- Force a backend: `'backend' => 'claude_cli'` — see Claude CLI in the loop
- Change provider mid-run: `php artisan provider:rotate superagent --reason=cookbook`
  — next call uses the next active provider, no code change needed

## See also

- 02 — Prompt caching
- 03 — Provider rotation
- 05 — Tracing quickstart
