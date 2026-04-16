# forgeomni/ai-core

Laravel package for unified AI execution across multiple backends: Claude CLI, Codex CLI, SuperAgent SDK, Anthropic API, OpenAI API. Includes a framework-agnostic CLI, a capability dispatcher, MCP server management, usage tracking, and cost analytics.

## Status

Phase 1 skeleton. Backends + Dispatcher + CLI work. Eloquent models, controllers, views, and full MCP manager port from SuperTeam are coming in follow-up commits.

## Install

```bash
composer require forgeomni/ai-core
```

## Quick CLI usage

```bash
# List backends and their availability
./vendor/bin/ai-core list-backends

# Call any backend
./vendor/bin/ai-core call "Hello" --backend=anthropic_api --api-key=sk-ant-...
./vendor/bin/ai-core call "Hello" --backend=claude_cli
./vendor/bin/ai-core call "Hello" --backend=superagent
```

## Usage in PHP

```php
use ForgeOmni\AiCore\Services\BackendRegistry;
use ForgeOmni\AiCore\Services\CostCalculator;
use ForgeOmni\AiCore\Services\Dispatcher;

$dispatcher = new Dispatcher(new BackendRegistry(), new CostCalculator());

$result = $dispatcher->dispatch([
    'prompt' => 'Hello',
    'backend' => 'anthropic_api',
    'provider_config' => ['api_key' => 'sk-ant-...'],
    'model' => 'claude-sonnet-4-5-20241022',
    'max_tokens' => 200,
]);

echo $result['text'];
```

## Architecture

```
Dispatcher ← BackendRegistry ← {ClaudeCli, CodexCli, SuperAgent, AnthropicApi, OpenAiApi}
         ← ProviderResolver   (looks up active provider from ProviderRepository)
         ← RoutingRepository  (task_type + capability → service)
         ← UsageTracker       (writes to UsageRepository)
         ← CostCalculator     (model pricing → USD)
```

Repositories (ProviderRepository, ServiceRepository, RoutingRepository, UsageRepository) are interfaces. The Laravel ServiceProvider auto-binds Eloquent implementations once the matching models are ported; alternative implementations (JSON files, external APIs) can be swapped in.
