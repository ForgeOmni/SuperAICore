# 安装指南 — forgeomni/superaicore

[English](INSTALL.md) · [简体中文](INSTALL.zh-CN.md) · [Français](INSTALL.fr.md)

本文介绍如何将 `forgeomni/superaicore` 完整安装到现有的 Laravel 10/11/12 项目中。

## 1. 环境要求

- PHP ≥ 8.1，启用 `ext-json`、`ext-mbstring`、`ext-pdo`
- Composer 2.x
- Laravel 10、11 或 12（全新项目同样适用）
- SQL 数据库（MySQL 8+、PostgreSQL 13+ 或 SQLite 3.35+）
- 按后端选择性安装：
  - `claude` CLI 在 `$PATH` 中 —— Claude CLI 后端
  - `codex` CLI 在 `$PATH` 中 —— Codex CLI 后端
  - `gemini` CLI 在 `$PATH` 中 —— Gemini CLI 后端
  - `copilot` CLI 在 `$PATH` 中（再跑 `copilot login`）—— GitHub Copilot CLI 后端
  - `kiro-cli` 在 `$PATH` 中（再跑 `kiro-cli login`；或设置 `KIRO_API_KEY` 走 headless，需 Pro / Pro+ / Power）—— Kiro CLI 后端（0.6.1+）
  - Anthropic API Key —— `anthropic_api`
  - OpenAI API Key —— `openai_api`
  - Google AI Studio Key —— `gemini_api`

## 2. 通过 Composer 安装

```bash
composer require forgeomni/superaicore
```

如果你**不需要** SuperAgent 后端，可以在安装前移除兄弟包依赖：

```bash
# 可选 —— 移除 SuperAgent SDK 依赖
composer remove forgeomni/superagent
# 然后在 .env 中：
# AI_CORE_SUPERAGENT_ENABLED=false
```

当 SDK 缺失时，`SuperAgentBackend` 会自我标记为不可用，Dispatcher 将自动回退到其余四个后端。

## 3. 发布配置与迁移

```bash
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan vendor:publish --tag=super-ai-core-views    # 仅在需要覆写 Blade 模板时执行
```

配置文件会放到 `config/super-ai-core.php`。迁移会创建 8 张表：

- `integration_configs`
- `ai_capabilities`
- `ai_services`
- `ai_service_routing`
- `ai_providers`
- `ai_model_settings`
- `ai_usage_logs`
- `ai_processes`

执行迁移：

```bash
php artisan migrate
```

## 4. 环境变量

最简 `.env`（启用 HTTP 后端）：

```dotenv
AI_CORE_DEFAULT_BACKEND=anthropic_api
ANTHROPIC_API_KEY=sk-ant-...
# 或使用 OpenAI：
OPENAI_API_KEY=sk-...
```

完整环境变量列表（默认值见 `config/super-ai-core.php`）：

```dotenv
# 路由与 UI
AI_CORE_ROUTES_ENABLED=true
AI_CORE_ROUTE_PREFIX=super-ai-core
AI_CORE_VIEWS_ENABLED=true
SUPER_AI_CORE_LAYOUT=super-ai-core::layouts.app

# 宿主集成（可选）
SUPER_AI_CORE_HOST_BACK_URL=https://your-host.app/dashboard
SUPER_AI_CORE_HOST_NAME="你的宿主应用"
SUPER_AI_CORE_HOST_ICON=bi-arrow-left
SUPER_AI_CORE_LOCALE_COOKIE=locale

# 后端
AI_CORE_CLAUDE_CLI_ENABLED=true
AI_CORE_CODEX_CLI_ENABLED=true
AI_CORE_GEMINI_CLI_ENABLED=true
AI_CORE_COPILOT_CLI_ENABLED=true
AI_CORE_KIRO_CLI_ENABLED=true
AI_CORE_SUPERAGENT_ENABLED=true
AI_CORE_ANTHROPIC_API_ENABLED=true
AI_CORE_OPENAI_API_ENABLED=true
AI_CORE_GEMINI_API_ENABLED=true
CLAUDE_CLI_BIN=claude
CODEX_CLI_BIN=codex
GEMINI_CLI_BIN=gemini
COPILOT_CLI_BIN=copilot
KIRO_CLI_BIN=kiro-cli
AI_CORE_COPILOT_ALLOW_ALL_TOOLS=true
# Kiro 的 --no-interactive 模式默认拒绝未预先授权的工具；除非使用
# --trust-tools=<categories> 预置白名单，否则保持 true（0.6.1+）。
AI_CORE_KIRO_TRUST_ALL_TOOLS=true
# Kiro API key 鉴权（headless，需 Pro / Pro+ / Power 订阅）。设置该变量
# 后 kiro-cli 会跳过浏览器登录流程。通常通过 provider type=kiro-api 存到 DB
# 里使用，仅当直接调 kiro-cli（不经 superaicore dispatcher）时才需要导出
# 这个 env（0.6.1+）。
# KIRO_API_KEY=ksk_...
# 0.5.8+：cli:status 中 copilot 行的可选 liveness 探测，默认关闭
# （每次状态轮询 spawn 一次 `copilot --help` 成本过高）。
SUPERAICORE_COPILOT_PROBE=false
# 0.6.0+：CLI 启动时可选的模型目录自动刷新。两个都要设置才会触发，
# 且本地覆盖文件超过 7 天才会真正执行；网络错误会被吞掉。
# SUPERAGENT_MODELS_URL=https://your-cdn/models.json
# SUPERAGENT_MODELS_AUTO_UPDATE=1
ANTHROPIC_BASE_URL=https://api.anthropic.com
OPENAI_BASE_URL=https://api.openai.com
GEMINI_BASE_URL=https://generativelanguage.googleapis.com

# 表名前缀（默认 sac_，置空则保留原始 ai_* 名称）
AI_CORE_TABLE_PREFIX=sac_

# 使用量、MCP、监控
AI_CORE_USAGE_TRACKING=true
AI_CORE_USAGE_RETAIN_DAYS=180
AI_CORE_MCP_ENABLED=true
AI_CORE_MCP_INSTALL_DIR=/var/lib/mcp
AI_CORE_PROCESS_MONITOR=false
```

## 5. 冒烟测试

```bash
# 查看当前环境下可用的后端
./vendor/bin/superaicore list-backends

# 通过 Anthropic API 来回测一次
./vendor/bin/superaicore call "ping" --backend=anthropic_api --api-key="$ANTHROPIC_API_KEY"
```

预期：返回一段短文本以及用量信息。

### Skill 与 sub-agent CLI 冒烟

如果本机已经装过 Claude Code 的 skill 或 sub-agent（项目 `./.claude/skills/`、`~/.claude/plugins/*/skills/`、用户 `~/.claude/skills/` 或 `~/.claude/agents/`），它们会被自动拾取：

```bash
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# --dry-run 只打印解析出来的命令，不真的调后端 CLI
./vendor/bin/superaicore skill:run <name> --dry-run

# 为每个 skill/agent 生成 Gemini 自定义命令
# （写入 ~/.gemini/commands/skill/*.toml 与 agent/*.toml）
./vendor/bin/superaicore gemini:sync --dry-run

# 把 Claude 风格 agent 翻译成 Copilot 的 .agent.md 格式
# （`agent:run --backend=copilot` 会自动触发；这里是手动预览）
./vendor/bin/superaicore copilot:sync --dry-run

# 同样的契约对 Kiro 也成立（0.6.1+）：agent 翻译成 ~/.kiro/agents/<name>.json
# （`agent:run --backend=kiro` 会自动触发；这里是手动预览）
./vendor/bin/superaicore kiro:sync --dry-run
```

不需要额外配置。不带 `--dry-run` 时会 shell out 到真实的后端 CLI（`claude`、`codex`、`gemini`、`copilot`、`kiro-cli`）—— 按需装：

```bash
npm i -g @anthropic-ai/claude-code
brew install codex        # 或 cargo install codex
npm i -g @google/gemini-cli
npm i -g @github/copilot   # 然后 `copilot login`（OAuth device flow）
# kiro-cli —— 按 https://kiro.dev/cli/ 安装，然后 `kiro-cli login`
# （或 export KIRO_API_KEY=ksk_... 走 Pro / Pro+ / Power 订阅的 headless 模式）
```

一键替代（推荐）—— 让 superaicore 自己检测并安装：

```bash
./vendor/bin/superaicore cli:status                 # 看哪几个缺
./vendor/bin/superaicore cli:install --all-missing  # 一次装齐（带确认提示）
```

### 模型目录冒烟测试（0.6.0+）

每当宿主 config 没有枚举某个模型，`CostCalculator` 与各引擎的 `ModelResolver` 会回退到 SuperAgent 的模型目录。不用改 `composer.json` 也不用 `config/super-ai-core.php`，直接查看已加载内容并刷新用户覆盖文件：

```bash
./vendor/bin/superaicore super-ai-core:models status                       # 内置 / 用户覆盖 / 远程 URL + 过期提示
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic    # 每百万 token 价格 + 别名
./vendor/bin/superaicore super-ai-core:models update                       # 从 SUPERAGENT_MODELS_URL 拉到 ~/.superagent/models.json
./vendor/bin/superaicore super-ai-core:models update --url https://…       # 本次运行临时指定 URL
./vendor/bin/superaicore super-ai-core:models reset -y                     # 删除用户覆盖文件
```

## 6. 打开后台 UI

默认挂载点为 `/super-ai-core`。开箱即用的路由带有 `['web', 'auth']` 中间件，因此先登录你的 Laravel 应用再访问：

- `http://your-app.test/super-ai-core/integrations`
- `http://your-app.test/super-ai-core/providers`
- `http://your-app.test/super-ai-core/services`
- `http://your-app.test/super-ai-core/usage`
- `http://your-app.test/super-ai-core/costs`

若需启用进程监控（仅管理员），设置 `AI_CORE_PROCESS_MONITOR=true`。

## 7. 无 UI / 仅服务模式

完全跳过路由和视图，直接从容器解析服务：

```dotenv
AI_CORE_ROUTES_ENABLED=false
AI_CORE_VIEWS_ENABLED=false
```

```php
$dispatcher = app(\SuperAICore\Services\Dispatcher::class);
$result = $dispatcher->dispatch([
    'prompt' => '帮我总结这张工单',
    'task_type' => 'summarize',
]);
```

## 8. 宿主侧使用量追踪 —— `UsageRecorder`（0.6.2+）

如果宿主应用不是走 `Dispatcher::dispatch()`，而是自己 spawn CLI（例如 `App\Services\ClaudeRunner`、阶段任务、`ExecuteTask` 流水线），这些执行不会自动写 `ai_usage_logs` —— Dispatcher 是唯一写入者。在每个 CLI 完成路径上调用一次 `UsageRecorder::record()`，即可写入一条正规记录，`cost_usd` / `shadow_cost_usd` / `billing_model` 全部按 catalog 自动补齐：

```php
use SuperAICore\Services\UsageRecorder;

// 已从 CLI 的 stream-json / stdout 中解析出的 tokens：
app(UsageRecorder::class)->record([
    'task_type'     => 'ppt.strategist',      // 任意能聚合的分组键
    'capability'    => 'agent_spawn',
    'backend'       => 'claude_cli',
    'model'         => 'claude-sonnet-4-5-20241022',
    'input_tokens'  => 12345,
    'output_tokens' => 6789,
    'duration_ms'   => 45000,
    'user_id'       => auth()->id(),
    'metadata'      => ['ppt_job_id' => 42],
]);
```

如果手头只有原始 CLI stdout、还没自己抽 token，`CliOutputParser` 覆盖了常见格式：

```php
use SuperAICore\Services\CliOutputParser;

$env = CliOutputParser::parseClaude($stdout);    // 或 parseCodex / parseCopilot / parseGemini
// $env = ['text' => '…', 'model' => '…', 'input_tokens' => 12345, 'output_tokens' => 6789, …]；不匹配返回 null
```

`UsageRecorder` 以单例注册；当 `AI_CORE_USAGE_TRACKING=false` 时自动 no-op。

## 9. 通过 `provider_types` config 扩展 provider type（0.6.2+）

SuperAICore 内置 9 种 provider type（`anthropic` / `anthropic-proxy` / `bedrock` / `vertex` / `google-ai` / `openai` / `openai-compatible` / `kiro-api` / `builtin`）—— 每种都在 `Services\ProviderTypeRegistry::bundled()` 里带有 label、图标、表单字段、env 键名、base-url env、允许的 backend、`extra_config → env` 映射。宿主应用可以重命名已有 type（例如把 `label_key` 指到宿主自己的 lang 命名空间），也可以加全新的 type，一段 config 搞定,不用 fork:

```php
// config/super-ai-core.php
return [
    // …其他键…

    'provider_types' => [
        // 重命名已有 type,其余字段继承默认值
        \SuperAICore\Models\AiProvider::TYPE_ANTHROPIC => [
            'label_key' => 'integrations.ai_provider_anthropic',
            'icon'      => 'bi-key',
        ],

        // 新增一个 bundle 里没有的 type。字段形状照着
        // ProviderTypeDescriptor::fromArray() 即可。registry 会自动
        // 驱动 /providers UI、env builder、AiProvider::requiresApiKey()
        // 以及各 backend 的 buildEnv() 调用,无需别处再改。
        'xai-api' => [
            'label_key'        => 'integrations.ai_provider_xai',
            'icon'             => 'bi-x-lg',
            'fields'           => ['api_key'],
            'default_backend'  => \SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT,
            'allowed_backends' => [\SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT],
            'env_key'          => 'XAI_API_KEY',
        ],
    ],
];
```

以后 SuperAICore 上游再加新 type(例如 `TYPE_ANTHROPIC_VERTEX_V2`),宿主只要跑一次 `composer update` 就会看到 —— **完全零代码改动**。Registry 在容器里注册为 `app(\SuperAICore\Services\ProviderTypeRegistry::class)`,常用三个入口:`get($type)` / `all()` / `forBackend($backend)`。

宿主应用过去复制过 SuperAICore provider-type 矩阵的（SuperTeam 0.6.2 之前在 `IntegrationController::PROVIDER_TYPES` + `ClaudeRunner::providerEnvVars()` 里维护过），现在可以把那些拷贝**替换为对 `ProviderTypeRegistry` + `ProviderEnvBuilder` 的一行代理**。[CHANGELOG.md](CHANGELOG.md) 的 "Host-app migration" 一节有 before/after 的代码片段。

## 10. 从 runner 类自动记录 usage（0.6.5+）

如果宿主有用 `Runner\Concerns\MonitoredProcess` trait spawn CLI 子进程的类（SuperTeam 的 `ClaudeRunner` 就是典型例子），任何一条 spawn 路径都可以把 `runMonitored()` 换成 `runMonitoredAndRecord()` 来自动写 `ai_usage_logs`。新方法会 buffer stdout、退出时用 `CliOutputParser` 解析、然后调 `UsageRecorder::record()` —— 一次方法调用替掉宿主 runner 每个 backend 通常要写的 20–40 行 parser + recorder 胶水。

```php
use Symfony\Component\Process\Process;

class MyRunner {
    use \SuperAICore\Runner\Concerns\MonitoredProcess;

    public function run(Task $task): int
    {
        $process = Process::fromShellCommandline(
            'claude -p "…" --output-format=stream-json --verbose'
        );

        // runMonitored() —— spawn + 注册到进程监控。旧行为,不碰输出格式。
        // runMonitoredAndRecord() —— 同上,外加退出时自动记一条 usage。
        return $this->runMonitoredAndRecord(
            process:         $process,
            backend:         'claude_cli',
            commandSummary:  'claude -p "review" --output-format=stream-json',
            externalLabel:   "task:{$task->id}",
            engine:          'claude',          // 驱动 CliOutputParser 选择
            context:         [
                'task_type'  => 'tasks.run',
                'capability' => 'agent_spawn',
                'user_id'    => $task->user_id,
                'provider_id'=> $task->provider_id,
                'metadata'   => ['task_id' => $task->id],
            ],
        );
    }
}
```

CLI 的 exit code 始终原样返回。如果 `CliOutputParser` 没能匹配当前流的格式(Codex / Copilot 的纯文本输出就会这样),不写任何行、打一条 `debug` 级 log —— 这是 opt-in 设计,就是为了避免静默改变 runner 的既有输出形态。

## 11. 升级

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-migrations --force
php artisan migrate
```

在使用 `--force` 覆盖配置前，请查看 [CHANGELOG.md](CHANGELOG.md) 中的破坏性变更。

**0.6.2 迁移** —— 给 `ai_usage_logs` 加两列（均可空）：`shadow_cost_usd decimal(12,6)` 与 `billing_model varchar(20)`。安全、非破坏性。历史行值为 `NULL`（仪表盘显示 `—`）；新写入由 Dispatcher 自动填充。如需清掉 0.6.1 之前残留的 `task_type=NULL` 测试行：

```sql
DELETE FROM ai_usage_logs WHERE task_type IS NULL AND input_tokens = 0 AND output_tokens = 0;
```

**0.6.6 迁移** —— 给 `ai_usage_logs` 加一列可空列 + 一个复合索引：`idempotency_key varchar(80)` + `(idempotency_key, created_at)`。为 Dispatcher 60s 去重窗口服务。

**0.6.7 —— 无迁移。** 纯运行时行为改动。两点值得复核：

1. **父进程是 `claude` shell 再起 Laravel server 的场景**（例如在 Claude Code 会话里敲 `php artisan serve`），升级后 claude 会开始正常认证。如果之前你自己的 runner 里做了手工 env-scrub 来绕这个坑,现在它冗余但无害。
2. **自带 `ProcessSource` 的宿主**应该把自己的 label 前缀加到新配置键,免得 `AiProcessSource` 在你已经渲染了富行之外再输出一条裸行:

   ```php
   // config/super-ai-core.php
   'process_monitor' => [
       'enabled' => env('AI_CORE_PROCESS_MONITOR', false),
       'host_owned_label_prefixes' => ['task:'],   // SuperTeam 约定
   ],
   ```

`AiProcessSource::list()` 的契约现在明确为 **live-only** —— 只返回当前在跑的 OS 进程。之前靠 `list()` 拿历史行的宿主,请直接查 `ai_processes` 表（仍然是每次 spawn 的完整审计日志）。

**0.6.8 —— 无迁移。** 纯增量功能。三点值得复核:

1. **接入 catalog 驱动的 MCP 同步是 opt-in 的。** 在 `.mcp-servers/mcp-catalog.json` 放一份目录,在 `.claude/mcp-host.json` 里选好项目 tier / agent tier 的 server 分配,然后 `php artisan claude:mcp-sync --dry-run` 预览。不跑这个命令的宿主零变化 —— 你不调用,它不改盘。shape 详见 `docs/mcp-sync.md`。

2. **升级 `SuperAgentBackend` 调用方。** 原来的 one-shot 调用方零改动(`max_turns` 仍默认 1,envelope 里新 key 都是加法)。SDK 已锁到 **`forgeomni/superagent` 0.8.9**。要真正吃到 in-process 路径的新能力,传这些:
   ```php
   $dispatcher->dispatch([
       'prompt'          => '…',
       'backend'         => 'superagent',
       'max_turns'       => 10,              // 真正跑 agentic loop
       'max_cost_usd'    => 1.50,            // Agent::withMaxBudget() 硬卡
       'mcp_config_file' => base_path('.mcp.json'),
       'provider_config' => ['provider' => 'kimi', 'region' => 'cn'],  // 区域感知
       'load_tools'      => ['agent'],       // 可选:开启 SDK 子 agent dispatch(AgentTool)
   ]);

   // 当传了 load_tools: ['agent'] 且 run 里实际分发了子 agent 时,envelope 多一个可选的
   // subagents key(SDK 0.8.9 productivity):
   //   [
   //     ['agentId' => 'research-jordan',
   //      'status' => 'completed',           // 或 'completed_empty' —— 零工具调用
   //      'filesWritten' => ['/abs/path.md'],
   //      'toolCallsByName' => ['Read' => 3, 'Write' => 1],
   //      'productivityWarning' => null,     // 调了工具但没写文件时有 advisory 字符串
   //      'totalToolUseCount' => 4],
   //     …,
   //   ]
   // `status === 'completed_empty'` 或 productivityWarning 非 null 时建议重 dispatch。
   // 没子 agent 运行时 key 不出现 —— 不走 AgentTool 的调用方零改动。
   ```

3. **一条命令调试 API provider。** `bin/superaicore api:status` 对所有有 API-key env 的 provider 做 5s cURL 探测;`--all` 覆盖全部 DEFAULT_PROVIDERS、`--json` 输出给 dashboard。auth 被拒(HTTP 401/403)、网络超时、key 未配,三种情况每个都有独立的 `reason`。

4. **弱模型 agent-spawn 加固自动生效。** 用 `AgentSpawn\Pipeline`(包括所有 `TaskRunner` + `spawn_plan_dir` 的宿主)的 caller 升级后零改动拿到五层防御:宿主端 `task_prompt` guard 注入(CJK 检测自动分中英文)、canonical ASCII `output_subdir`、fanout 前清早产 consolidator 文件、fanout 后 contract 审计、语言感知的 consolidation prompt(禁止自创错误文件名)。两个副作用值得注意:
   - 每个 agent 的 `run.log` / 提示词 / 执行脚本现在写到 `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/`,而不是 `$outputRoot/<agent>/`。用户可见输出目录只放真交付物(`.md` / `.csv` / `.png`)。宿主里有 glob `$outputRoot/<agent>/run.log` 的地方需要更新路径。
   - `Orchestrator::run()` 返回的每行 report 现在可能带 `warnings[]`。只读 `exit` / `log` / `duration_ms` / `error` 的旧调用方源码兼容(key 在 PHPDoc 里标了 optional)。

**0.6.9 —— 无迁移。** 新增表面 + SDK bump 自带的五项自动修复。Composer 约束从 `^0.8.0` 抬到 `^0.9.0`。四点值得复核:

1. **Qwen provider key 重绑（SDK 层）。** SDK 0.9.0 把 `qwen` 注册键重绑到 OpenAI-兼容 provider(`<region>/compatible-mode/v1/chat/completions`),原 DashScope 原生 body shape 搬到 `qwen-native`。如果你在 wire 层面依赖 `parameters.thinking_budget` / `parameters.enable_code_interpreter` 等 DashScope 原生字段,切换 provider 配置:
   ```php
   'provider_config' => ['provider' => 'qwen-native', 'region' => 'cn'],
   ```
   两个 key 共享 `QWEN_API_KEY` / `DASHSCOPE_API_KEY` env。新的默认 `qwen` 就是 Alibaba 自家 `qwen-code` CLI 生产里用的路径 —— 没动 DashScope 原生字段的就别改。`ApiHealthDetector::DEFAULT_PROVIDERS` 现在包含这两个 key,`api:status` 会并排显示两条线路。

2. **`SuperAgentBackend` 三个新选项。** 全部增量、全部可选:
   ```php
   $dispatcher->dispatch([
       'prompt'           => '…',
       'backend'          => 'superagent',
       'provider_config'  => ['provider' => 'kimi', 'region' => 'cn'],

       // 新增 —— 厂商私有 wire 字段,深合并到请求 body
       'extra_body'       => ['custom_vendor_field' => 'value'],

       // 新增 —— capability-routed features;不支持的 provider 静默跳过
       'features'         => [
           'prompt_cache_key' => ['session_id' => $sessionId],  // Kimi 会话级 prompt cache
           'thinking'         => ['budget' => 4000],             // CoT 带兜底
       ],
       // 便捷写法: 'prompt_cache_key' => $sessionId 自动映射到
       // features.prompt_cache_key.session_id。

       // 新增 —— 流处理 handler 外再包一层 loop-detection harness
       'loop_detection'   => true,    // 或: ['tool_loop_threshold' => 7, ...]
   ]);
   ```
   `loop_detection` 捕获 `TOOL_LOOP`(5 次同工具+同参数)、`STAGNATION`(8 次同名称)、`FILE_READ_LOOP`(最近 15 次里 8 次读取类,带冷启动豁免)、`CONTENT_LOOP`(50 字符滑窗 10 次)、`THOUGHT_LOOP`(3 次同 thinking 文本)。违规通过 SDK wire event 发出 —— 不开启的调用方 AICore envelope 字节级不变。

3. **实时模型 catalog 刷新。** 新子命令:
   ```bash
   ./bin/superaicore super-ai-core:models refresh              # 刷新所有有 env 凭据的 provider
   ./bin/superaicore super-ai-core:models refresh --provider=kimi
   php artisan super-ai-core:models refresh --provider=qwen
   ```
   把每个 provider 的实时 `GET /models` 拉进 `~/.superagent/models-cache/<provider>.json`。overlay 在用户 override 之上、`register()` 运行时注册之下,bundled 定价在厂商 `/models` 不返回费率时保留。`CostCalculator` / `ModelResolver` 下一次调用自动接上 —— 无需重启,无需重新 publish 配置。

4. **Kimi Code / Qwen Code OAuth。** 没有 API key 的话,通过 SDK CLI 交互登录:
   ```bash
   ./vendor/bin/superagent auth login kimi-code     # 对 auth.kimi.com 走 RFC 8628 device flow
   ./vendor/bin/superagent auth login qwen-code     # 对 chat.qwen.ai 走 device flow + PKCE S256
   ```
   token 会落到 `~/.superagent/credentials/<kimi-code|qwen-code>.json`。`ApiHealthDetector::filterToConfigured()` 现在会把这两个文件也识别为 "已配置",即便没有 `KIMI_API_KEY` / `QWEN_API_KEY` env,`api:status` 和 `/providers` 依然会显示。Anthropic OAuth 刷新路径现在跨进程 `flock` 串行化,Laravel 多 worker 共用已存的 OAuth 凭据时不会再互相覆盖 refresh token。

**针对 mcp.json 里声明 `oauth:` 块的 MCP 服务器**,UI 可以调用 `McpManager::oauthStatus($key)` / `oauthLogin($key)` / `oauthLogout($key)`。`oauthLogin()` 在 device-flow 轮询期间阻塞 stdio —— 放队列任务里跑,别在 request 内联。既有的 `startAuth()` / `clearAuth()` / `testConnection()`(处理 LinkedIn scraper 这类浏览器登录 / session-dir 服务器)不受影响。

**0.7.0 —— 无迁移。** 新增接口 + 修复一处长期存在的映射问题。Composer 约束从 `^0.9.0` 抬到 `^0.9.1`。五件事值得留意:

1. **两个新 provider 类型:`openai-responses` 和 `lmstudio`。** 都走 `superagent` 后端（SDK 键分别为 `openai-responses` / `lmstudio`）。
   - **OpenAI Responses API** —— 按量模式:添加一个 `type = openai-responses` 且带 API key 的 provider 行。ChatGPT 订阅模式:把 `api_key` 留空，将 `access_token`（来自宿主 ChatGPT OAuth 流程）存进 `extra_config.access_token` —— SDK 会自动把 base URL 切到 `chatgpt.com/backend-api/codex`。Azure OpenAI:`base_url` 填成 `https://<name>.openai.azure.com/openai/deployments/<deployment>` —— SDK 自动追加 `api-version=2025-04-01-preview` query（通过 `extra_config.azure_api_version` 覆盖）。
   - **LM Studio** —— `base_url` 指向本地 LM Studio 服务（默认 `http://localhost:1234/v1`）。无需真 API key；SDK 自动合成占位 `Authorization` 头。适合断网 / on-prem 场景。

2. **幂等 key 通过 SDK 往返。** 如果原本就在 `Dispatcher::dispatch()` 选项里传 `idempotency_key`，业务代码不用改 —— 但值现在会随 SDK 的 `AgentResult` 流转，而不是旁路经 UsageRecorder。Dispatcher 和 UsageRecorder 即便跑在不同进程上，写入侧也无需重算 key。基于 `external_label` 派生的 auto-key 同样适用:Dispatcher 先算出 `"{backend}:{external_label}"` 转发给 `Agent::run()`，写 `ai_usage_logs` 时优先采用 envelope 回显值。

3. **W3C trace context 透传。** 宿主若有中间件读取入站 `traceparent` 头，转发给 Dispatcher 即可:
   ```php
   $dispatcher->dispatch([
       'prompt'       => '…',
       'backend'      => 'superagent',
       'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
       'traceparent'  => $request->header('traceparent'),  // null 时静默跳过
       'tracestate'   => $request->header('tracestate'),
   ]);
   ```
   SDK 把这些投影到 Responses API 的 `client_metadata`，OpenAI 侧日志就能和宿主分布式 trace 对上。非法字符串静默丢弃 —— 无条件传也安全。

4. **分类的 `ProviderException` 子类。** `SuperAgentBackend` 的 catch 阶梯现在在通用 `\Throwable` 之前先分 SDK 的六个子类（`ContextWindowExceeded` / `QuotaExceeded` / `UsageNotIncluded` / `CyberPolicy` / `ServerOverloaded` / `InvalidPrompt`），每个都以稳定的 `error_class` tag + `retryable` 标记记日志。契约不变 —— 失败仍然返回 `null` —— 调用点不破。需要更聪明路由的宿主继承 `SuperAgentBackend` 并 override `logProviderError(\Throwable $e, string $code)` seam。

5. **按 provider 类型声明 HTTP 头。** descriptor 新增两个字段 —— `http_headers`（字面量 header → value）和 `env_http_headers`（header → env 变量名，请求时读），无需改代码就能给某个类型的每次 SDK 调用注入 `OpenAI-Project`、`LangSmith-Project`、`OpenRouter-App` 等头。示例:
   ```php
   // config/super-ai-core.php
   'provider_types' => [
       // 每次 OpenAI 调用都打上自己的 app id，同时识别 OPENAI_PROJECT 环境变量
       // （SDK 在 env 变量未设时静默跳过这个 header，没配置 project-scoped key
       // 的宿主也不会出错）。
       \SuperAICore\Models\AiProvider::TYPE_OPENAI => [
           'http_headers'     => ['X-App' => 'my-host-app'],
           'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
       ],

       // 新 Responses API 类型同理 —— 注入 LangSmith project 头，跨 provider 追踪
       // 不需要额外的 wrapper 层。
       \SuperAICore\Models\AiProvider::TYPE_OPENAI_RESPONSES => [
           'env_http_headers' => ['Langsmith-Project' => 'LANGSMITH_PROJECT'],
       ],
   ],
   ```

**已经存在的 `openai-compatible` / `anthropic-proxy` provider。** 0.7.0 之前，这类行在 `provider_config.provider` 没手工设时会静默路由到 SDK 的 `anthropic` provider —— `anthropic-proxy` 恰好对，`openai-compatible` 就会失败。0.7.0 开始，descriptor 的 `sdk_provider` 负责正确映射（`anthropic` / `openai`）。如果宿主显式设了 `provider_config.provider`，没变化。如果你依赖过那个 bug 默认，`openai-compatible` 行现在开始按正确路由工作。

深入示例（多轮 Responses、LangSmith 追踪、LAN 内 LM Studio、宿主级异常路由、per-provider HTTP 头覆盖）见 `docs/advanced-usage.zh-CN.md`。

**0.7.1 —— 无迁移。** 纯增量契约 —— `Contracts\ScriptedSpawnBackend` 和 `StreamingBackend` 并列（不是替代）。同一版本里六个 CLI 后端（`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`）全部实现之。宿主里此前为每个后端写的 `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), … }`（任务 spawn 一份、one-shot chat 再一份）可以整体塌缩成一次多态调用:

```php
use SuperAICore\Services\BackendRegistry;

$backend = app(BackendRegistry::class)->forEngine($engineKey);  // 可空 —— 引擎关掉时返回 null
$process = $backend->prepareScriptedProcess([
    'prompt_file'  => $promptFile,
    'log_file'     => $logFile,
    'project_root' => $projectRoot,
    'model'        => $model,
    'env'          => $env,                     // 宿主构造（读 IntegrationConfig）
    'disable_mcp'  => $disableMcp,              // 主要是 Claude 用
    'codex_extra_config_args' => $codexArgs,    // 主要是 Codex 用
]);
$process->start();

// 一次性 chat 的兄弟方法 —— argv 组装、输出解析、ANSI 去色都在 backend 自己做:
$response = $backend->streamChat($prompt, function (string $chunk) {
    echo $chunk;
});
```

迁移完成后，未来新增的 CLI 引擎只要实现 `ScriptedSpawnBackend` 契约，就会在宿主每条代码路径里自动出现 —— 再无需要加新 `match` 分支。`Support\CliBinaryLocator` 在 service provider 注册为单例，宿主侧 CLI 路径探测与包内各 backend 走一套（`~/.npm-global/bin` / `/opt/homebrew/bin` / nvm 路径 / Windows `%APPDATA%/npm`）。`ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS` 现在是公开常量，仍自行组装 `claude` 进程的宿主可以直接拿规范的五标记 scrub 列表。

完整 before/after 迁移模式见 `docs/advanced-usage.zh-CN.md` §12；上下文见 `docs/host-spawn-uplift-roadmap.md`。

**0.8.1 —— 无迁移。** 两个 opt-in 改动，升级时不启用也完全无感。

1. **通过 `mcp.portable_root_var` 让 `.mcp.json` 写入可移植。** 默认仍为 `null`，"路径全部写绝对值" 的 legacy 行为保留。当你希望生成的 `.mcp.json` 跨机器 / 跨用户 / 跨容器复制或同步都不会失效时（典型场景:`mcp` 安装目录就在项目树内，跟项目一起搬迁），就启用它:

   ```dotenv
   # .env —— 任何宿主 MCP runtime 会导出的环境变量名都可以
   AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
   ```

   ```jsonc
   // .claude/settings.local.json —— Claude Code 在 spawn MCP 时展开 ${SUPERTEAM_ROOT}
   { "env": { "SUPERTEAM_ROOT": "${PWD}" } }
   ```

   开启后，所有 `McpManager::install*()` 写入路径会输出裸命令（`node` / `php` / `uvx` / `uv` / `python`），并把 `projectRoot()` 下的路径改写成 `${SUPERTEAM_ROOT}/<rel>`。三个 backend-sync 辅助方法（`superfeedMcpConfig` / `codexOcrMcpConfig` / `codexPdfExtractMcpConfig`）也遵循同一开关。出口到 per-machine 目标时（Codex `~/.codex/config.toml`、Gemini / Claude / Copilot / Kiro / Kimi 的 user-scope MCP 配置、`codex exec -c` 运行时 flag），通过 `materializePortablePath()` 把占位符再展开回绝对路径，那些只接受字面值的 backend 一样能正常 spawn。`McpManager` 新增的辅助方法:`portablePath()` / `portableCommand()` / `portableRootVar()` / `materializePortablePath()` / `materializeServerSpec()`。完整 recipes（容器化宿主、多用户挂载、当环境变量未在运行时导出时的处理）见 `docs/advanced-usage.zh-CN.md` §13。

2. **`/providers` 页基于 CLI 可用性收敛 UI。** 纯 UI 修复 —— 不动 controller / 路由 / DB。`$PATH` 上找不到二进制的 CLI 引擎（`claude` / `codex` / `gemini` / `copilot` / `kiro` / `kimi`），引擎开关会渲染成 `disabled`（带提示 + 隐藏字段被钳制），下方 per-backend 表里那条合成的 "built-in (local CLI login)" 行也会在引擎关闭或 CLI 缺失时被隐藏。当 built-in 与任何外部 provider 都不适用时，表格底部会出现一行明确的空态，指出真正的原因。之前用户切到 "Engine on" 后再发现 runtime 静默失败的工单，可以从此免单。

**0.8.5 —— 无迁移。** SDK uptake + 一处正确性修复；不动 DB / config。Composer 约束从 `^0.9.0` 升到 `^0.9.5`。三件值得知道的事：

1. **针对 Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio 的多轮 tool-use 回放终于能工作了。** 0.9.5 之前 SDK 的 `ChatCompletionsProvider::convertMessage()` 在第一个 `tool_use` block 提前 return（丢掉同级 text 和并行 tool call），并且访问根本不存在的 `ContentBlock` 属性 —— 每个回放的 tool call 都以 `{id: null, name: null, arguments: "null"}` 出去。任何用 `Dispatcher::dispatch(['backend' => 'superagent', 'max_turns' => 10, …])` 跑这些 provider 的宿主，升级前都是静默坏的。无需改调用代码；SDK 新加的 `Conversation\Transcoder` 把六种 wire family 全统一到一个 converter，一次修复全部 provider 同步生效。

2. **`SuperAgentBackend::buildAgent()` 现在永远把构造好的 `LLMProvider` 实例交给 SDK**（不再交字符串 provider 名 + 散开的 `llmConfig` 键）。生产路径走 `Dispatcher`，从来不检查 `$agentConfig['provider']`，因此对它无感。子类 `SuperAgentBackend` 并 override `makeAgent()` 的宿主，应该把之前断言 `$agentConfig['provider'] === 'sa-test'` 的测试改为 `instanceof \SuperAgent\Contracts\LLMProvider` —— 范例参见 `tests/Unit/SuperAgentBackendTest.php::test_no_region_still_hands_llmprovider_instance_to_agent`。`SuperAgentBackend` 新增的 `makeProvider()` seam 是测试替身的注入点，不必再走 `ProviderRegistry::register()`。

3. **`Agent::switchProvider($name, $config, $policy)` 现在可用。** 直接包 `SuperAgentBackend` 并希望进程内对话中途切 provider family 的宿主可以用。SuperAICore 自己的 `FallbackChain` 走的是 CLI 子进程级别（不同的关心面），没有用这个特性。`HandoffPolicy::default() / preserveAll() / freshStart()` 三种预设和跨家族 wire-format 编码规则见 SDK 的 `[0.9.5]` CHANGELOG。

0.8.1 引入的命名空间 typo 修复（`makeProvider()` 当时返回根本不存在的 `\SuperAgent\Providers\LLMProvider`，导致 SuperAgent in-process backend 在 0.8.1 → 0.8.2 期间静默全坏）也是这次发布的一部分。之前发现 `Dispatcher::dispatch(['backend' => 'superagent', …])` 每次都返回 `null` 的宿主，现在应该能看到真实的 envelope 了 —— 用 `bin/superaicore api:status` 对你 SuperAgent 路由的 provider 验证一下，或跑包内测试套件:480 tests / 1380 assertions。

## 常见问题

- **`Class 'SuperAgent\Agent' not found`** —— 你移除了 `forgeomni/superagent`，但仍保留 `AI_CORE_SUPERAGENT_ENABLED=true`。设为 `false` 或重新安装 SDK。
- **CLI 后端不可用** —— 执行 `which claude` / `which codex`。若为空，安装对应 CLI，或在 `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` 中填写绝对路径。
- **`ai_usage_logs` 没有记录** —— 检查 `AI_CORE_USAGE_TRACKING=true` 且迁移已执行。
- **`vendor:publish` 提示不明确** —— 显式传入上面列表中的 `--tag`。
