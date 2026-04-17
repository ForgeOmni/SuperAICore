# forgeomni/superaicore

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

用于统一调度多种 AI 后端的 Laravel 包，支持 **Claude CLI**、**Codex CLI**、**SuperAgent SDK**、**Anthropic API**、**OpenAI API**。内置独立于框架的 CLI、基于能力（capability）的调度器、MCP 服务器管理、使用量记录、成本分析，以及一套完整的后台管理 UI。

在干净的 Laravel 项目中可独立运行。UI 可选、可完全替换，既能嵌入宿主应用（例如 SuperTeam），也可以在仅使用服务层时关掉。

## 与 SuperAgent 的关系

`forgeomni/superaicore` 和 `forgeomni/superagent` 是**兄弟包，并非父子依赖关系**：

- **SuperAgent** 是一个轻量级的 PHP 进程内 SDK，专注于驱动单个 LLM 的 tool-use 循环（一个 agent、一段会话）。
- **SuperAICore** 是 Laravel 级的编排层 —— 负责挑选后端、解析 provider 凭证、按能力路由、记录用量、计算成本、管理 MCP 服务器，并提供后台 UI。

**SuperAICore 并不依赖 SuperAgent 才能工作。** SuperAgent 只是五个后端之一。另外四个（Claude CLI、Codex CLI、Anthropic API、OpenAI API）无需它即可运行，且 `SuperAgentBackend` 在 SDK 缺失时会通过 `class_exists(Agent::class)` 检查优雅地报告为不可用。如果你不需要 SuperAgent，只需在 `.env` 中设置 `AI_CORE_SUPERAGENT_ENABLED=false`，Dispatcher 会自动回退到其余后端。

`composer.json` 中的 `forgeomni/superagent` 依赖只是为了开箱即用地启用 SuperAgent 后端；若你从不使用它，可以在宿主项目 `composer install` 之前从 `composer.json` 中移除该条目 —— SuperAICore 的其余代码都不会引用 SuperAgent 命名空间。

## 特性

- **五种可插拔后端** —— Claude CLI、Codex CLI、SuperAgent、Anthropic API、OpenAI API，统一实现同一套 `Dispatcher` 契约。
- **Provider / Service / Routing 模型** —— 将抽象能力（`summarize`、`translate`、`code_review` 等）映射到具体服务，再将服务绑定到 provider 凭证。
- **MCP 服务器管理器** —— 在后台 UI 中安装、启用、配置 MCP 服务器。
- **使用量追踪** —— 每次调用将 prompt / response tokens、耗时、成本写入 `ai_usage_logs` 表。
- **成本分析** —— 按模型价格表汇总 USD 费用，并提供带图表的仪表盘。
- **进程监控** —— 查看正在运行的 AI 进程、跟踪日志、终止僵尸进程。
- **三语 UI** —— 英文、简体中文、法文，可在运行时切换。
- **宿主友好** —— 支持关闭路由/视图、替换 Blade 布局，或在父应用中复用返回链接与语言切换器。

## 环境要求

- PHP ≥ 8.1
- Laravel 10、11 或 12
- Guzzle 7、Symfony Process 6/7

下列为可选，仅当启用对应后端时需要：

- `claude` CLI 在 `$PATH` 中（Claude CLI 后端）
- `codex` CLI 在 `$PATH` 中（Codex CLI 后端）
- Anthropic 或 OpenAI API Key（HTTP 后端）

## 安装

```bash
composer require forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
```

完整步骤见 [INSTALL.zh-CN.md](INSTALL.zh-CN.md)。

## CLI 快速上手

```bash
# 查看所有后端及可用状态
./vendor/bin/super-ai-core list-backends

# 调用任意后端
./vendor/bin/super-ai-core call "你好" --backend=anthropic_api --api-key=sk-ant-...
./vendor/bin/super-ai-core call "你好" --backend=claude_cli
./vendor/bin/super-ai-core call "你好" --backend=superagent
```

## PHP 调用示例

```php
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;

$dispatcher = new Dispatcher(new BackendRegistry(), new CostCalculator());

$result = $dispatcher->dispatch([
    'prompt' => '你好',
    'backend' => 'anthropic_api',
    'provider_config' => ['api_key' => 'sk-ant-...'],
    'model' => 'claude-sonnet-4-5-20241022',
    'max_tokens' => 200,
]);

echo $result['text'];
```

## 架构

```
Dispatcher ← BackendRegistry  ← { ClaudeCli, CodexCli, SuperAgent, AnthropicApi, OpenAiApi }
           ← ProviderResolver  （从 ProviderRepository 读取当前 provider）
           ← RoutingRepository （task_type + capability → service）
           ← UsageTracker      （写入 UsageRepository）
           ← CostCalculator    （模型价格表 → USD）
```

所有 Repository 都是接口。ServiceProvider 默认绑定 Eloquent 实现；你可以替换为 JSON 文件、Redis 或外部 API，调度器无需改动。

## 后台 UI

当 `views_enabled` 为真时，包会在配置的路由前缀（默认 `/super-ai-core`）下挂载以下页面：

- `/integrations` —— Provider、服务、API Key、MCP 服务器
- `/providers` —— 按后端维护凭证与默认模型
- `/services` —— 任务类型路由
- `/ai-models` —— 模型价格覆盖
- `/usage` —— 可筛选的调用日志
- `/costs` —— 成本仪表盘
- `/processes` —— 实时进程监控（仅管理员，默认关闭）

## 配置

发布后的配置文件 `config/super-ai-core.php` 覆盖：宿主集成（返回链接、图标、名称）、语言切换器、路由/视图注册开关、逐个后端的开关、默认后端、使用量保留天数、MCP 安装目录、进程监控开关，以及每个模型的价格。所有字段均有内嵌注释说明。

## 许可证

MIT。见 [LICENSE](LICENSE)。
