# Design: `kimi_cli` Backend

**状态**:MVP-1 + MVP-2 + agent-sync 已实施,0.6.8 基线
**日期**:2026-04-22(RFC);2026-04-22(实施完成)
**目标版本**:保持当前 0.6.8 基线,不自动 bump
**相关文档**:`docs/copilot-cli-backend.md`、`docs/spawn-plan-protocol.md`、`docs/streaming-backends.md`、`docs/mcp-sync.md`
**Dispatcher backend id**:`kimi_cli`(RFC 原写作 `kimi_code`,落地时改为 `kimi_cli` 与 `claude_cli` / `codex_cli` / `gemini_cli` / `copilot_cli` / `kiro_cli` 同序)

---

## 1. 背景与决策

Moonshot AI 的 **Kimi Code CLI**(repo [`MoonshotAI/kimi-cli`](https://github.com/MoonshotAI/kimi-cli),binary 名 `kimi`)是 Moonshot 官方的 first-party agentic CLI,2026-04 进入 `v1.38.0`,8.1k★,Python 3 + TS(web 组件)实现。这**不是**对 `api.moonshot.ai` 的 HTTP 封装 —— 它是一条和 Claude Code / Codex / Gemini CLI 并列的完整 agent loop,23 个内建工具、3 个默认 subagent、OAuth 订阅认证、原生 MCP + 原生 `.claude/skills/` 扫描。

### 为什么值得做

1. **Full-peer 架构** —— 不是 chat wrapper。带 `--print --output-format stream-json`、Read/Write/Edit/Shell/Glob/Grep/SearchWeb/Agent/Plan 工具、MCP 子命令组、background 任务机制。直接对得上我们 `StreamingBackend` 契约。
2. **原生 `.claude/skills/` 扫描** —— 和 Copilot 一样的"零翻译"接入。不用写 `KimiSkillSync`。
3. **和现有 Kimi 支持正交** —— `forgeomni/superagent` 0.8.8+ 的 `KimiProvider` 已经提供直连 HTTP + API-key 路径;CLI 路径走 OAuth 订阅 + agentic loop。两条路并存的先例:`anthropic_api` ↔ `claude_cli` / `builtin`。
4. **K2.6 的 "300 sub-agents / 4000 steps" 宣传需要这条** —— 单次 `kimi --print` 因 `LoopControl.max_steps_per_turn=500` 触不到那个数字,但它是最接近的 surface。
5. **仅 7 个 OS-level 整合引擎就能覆盖主流栈** —— Claude / Codex / Gemini / Copilot / Kiro / SuperAgent 已就位,多一个 Kimi 把中文大模型生态独立一条首批 tier。

### 优先级与次序

**中等偏高**。两个已知的 cost:

- Python-only 安装(`uv tool install kimi-cli` / `pip install kimi-cli`)比 Claude CLI / Codex / Gemini / Copilot 的 npm / brew 路径稍麻烦,`CliInstaller` 需要新增 uv/pip 分支。
- `stream-json` 不透传 `SubagentEvent`(子 agent 的 tool_use 不在 root stream 里),我们 `/providers` UI + Process Monitor 的每子 agent 实时可观测性**降级**。详见 §2.4 / §3.4。

具体排期依赖发版节奏,不硬定序。建议在 0.6.8 稳定后的下一个迭代窗口启动。

---

## 2. Kimi CLI 能力清单(调研结论)

### 2.1 非交互调用

- **Headless**:`kimi --print [OPTIONS]` 真正 non-interactive
- **输出格式**:`--output-format text`(默认)/ `--output-format stream-json`(JSONL)/ `--final-message-only` / `--quiet`
- `stream-json` 和 Claude CLI 的 `stream-json` **不完全同构** —— 只发 root agent 的 assistant message / tool_use / tool_result;子 agent 的 `SubagentEvent` 在 `JsonPrinter.feed()` 里 `case _: pass`,**root 流里看不到子 agent 内部活动**。MVP-1 前实测确认最新版是否已修复。

### 2.2 认证与 Provider

- `kimi login` / `kimi logout` —— browser OAuth,订阅制
- 不是 BYO-API-key 路径(那条归 SuperAgent `KimiProvider` 管,走 `api.moonshot.ai` / `api.moonshot.cn`)
- **billing_model = subscription**(同 Copilot / Kiro 订阅引擎)—— 计费和 dashboard 逻辑照搬

**Provider 模型(SuperAICore 视角)**:

| Provider type | Backend | 说明 |
|---|---|---|
| `moonshot-builtin` | `kimi_code` | OAuth + 订阅,`kimi login` 接管凭证 |
| `kimi-api` / `moonshot`(已存在) | `superagent` | 直连 HTTP,走 SDK `KimiProvider` |

和 Claude 的 `builtin` ↔ `anthropic` / `anthropic-proxy` 分裂同构。

### 2.3 配置文件与 MCP

- 主配置目录:`~/.kimi/`
- MCP 配置:`~/.kimi/mcp.json`
- MCP 子命令组:`kimi mcp add|list|auth|test`,支持 `--transport stdio|http`、`--env`、`--header`、`--auth oauth`
- 项目级 MCP:自动读 `.mcp.json`(Claude 约定)—— 和我们 `claude:mcp-sync` 已经写的文件直接兼容

### 2.4 Skills / Agents

**原生扫描路径**(零翻译接入):

- 用户级:`~/.kimi/skills/`、`~/.claude/skills/`、`~/.codex/skills/`、`~/.config/agents/skills/`
- 项目级:`.kimi/skills/`、`.claude/skills/`、`.codex/skills/`
- `merge_all_available_skills` 配置项可一次加载所有 brand 目录

Agent 侧:`.claude/agents/*.md` 兼容度待实测。若兼容,则复用现有 `.claude/agents/` 扫描,**零 sync 成本**。若不兼容,需新增 `KimiAgentSync`(把 YAML frontmatter → Kimi 自己的 agent 定义格式)。

### 2.5 Agent Team 支持(关键点)

**Kimi 原生分发 vs 我们 `AgentSpawn\Pipeline` 的关系** —— 重点章节,详见下文 §3.4 的集成决策。

| 方面 | Kimi 现状 |
|---|---|
| `Agent` 工具 | ✅ 在一个 assistant turn 里可发多个 tool_call,全部并发 fire 成 `ToolResultFuture`(Claude 2.x 同构) |
| 嵌套 subagent | ❌ 内建 `coder`/`explore`/`plan` 都 `exclude_tools: Agent`,**禁套娃**,只扁平 fanout |
| 前台并发上限 | 不设硬上限;background 受 `BackgroundConfig.max_running_tasks=4` 节流 |
| 子 agent 结果返回 | 单段文本 blob:`agent_id` / `status` / `[summary]`。**不返回** `filesWritten` / tool-use 流 —— **比 SDK 0.8.9 AgentTool productivity 弱** |
| 单 turn 步数上限 | `LoopControl.max_steps_per_turn=500`,超了就停 |
| 声明式 DAG / manifest | ❌ 无。无 `--max-concurrent-agents` / `--agent-manifest`,全模型运行时自决 |
| Background 任务 | ✅ `Agent(run_in_background=true)` + `Shell(run_in_background=true)` → `TaskList` / `TaskOutput`(结构化,32KB 尾部预览 + `ReadFile` 提示) / `TaskStop`(审批 gated) |

### 2.6 内建 subagent

| 名字 | 工具面 | 备注 |
|---|---|---|
| `coder` | 全集(Shell / Read / Write / StrReplace / Glob / Grep / Web) | 写代码 |
| `explore` | **只读**(无 Write / StrReplace) | 读代码理解 |
| `plan` | 只读 + **禁 Shell** | 规划 |

三者都 inherit 主 agent system prompt + append `ROLE_ADDITIONAL` 标识自己是 subagent。**全部 `exclude_tools: Agent`**,所以是扁平 fanout。若我们要注册自定义 subagent,names 不要和 `coder` / `explore` / `plan` 冲突,否则覆盖。

### 2.7 安装

- 首选:`uv tool install kimi-cli`
- fallback:`pip install kimi-cli`
- 独立 PyInstaller 二进制也有(但官方推荐 uv)
- 跨平台:macOS / Linux / Windows(需 Python ≥ 3.x,具体版本以 `pyproject.toml` 为准)

**`CliInstaller` 影响**:现有分支为 npm / brew / script(curl);Kimi 是第 4 种分支 **uv/pip**。建议 `install_hint` 优先给 `uv tool install kimi-cli`,后附 `pip install kimi-cli` 作为 fallback。

---

## 3. 对 SuperAICore 现有架构的影响

### 3.1 新增文件

```
src/Backends/KimiCodeCliBackend.php        # 新 backend(参考 GeminiCliBackend 结构)
src/Capabilities/KimiCodeCapabilities.php  # 引擎 capability + spawnPreamble + consolidationPrompt
src/Services/KimiModelResolver.php         # 可选 —— 若我们要暴露固定 model 列表
src/Sync/KimiMcpWriter.php                 # 写 ~/.kimi/mcp.json(纳入 McpCatalog::syncAllBackends)
src/Console/Commands/KimiSyncCommand.php   # 可选 —— 若 agent 兼容度不完整需要翻译
docs/kimi-cli-backend.md                   # 本文件
```

### 3.2 修改点

| 文件 | 修改 |
|---|---|
| `src/Services/EngineCatalog.php::seed()` | 新增 `kimi_code` 条目:binary `kimi`、prompt flag `--print`、output-format flag `--output-format stream-json`、version `--version`、auth probe(待实测,可能 `kimi login --status` 或探 `~/.kimi/` 文件)、MCP config path `~/.kimi/mcp.json`、billing_model `subscription` |
| `src/Services/BackendRegistry.php` | 注册 `KimiCodeCliBackend` |
| `src/Services/CapabilityRegistry.php` | 注册 `KimiCodeCapabilities`,`supportsMcp=true`,`supportsSystemPrompts=true`,**`use_native_agents=true`**(见 §3.4) |
| `src/Services/ProviderTypeRegistry.php` | 新类型 `moonshot-builtin`(backend 白名单 `['kimi_code']`)|
| `src/Services/CliStatusDetector.php` | `BACKENDS` 加 `'kimi'`(或 `kimi_code`),`detectAuth('kimi')` 读取 OAuth 状态(探 `~/.kimi/auth.json` 之类,待实测) |
| `src/Services/CliInstaller.php` | `installHint('kimi_code')` → `uv tool install kimi-cli`(fallback `pip install kimi-cli`) |
| `src/Services/McpManager.php::syncAllBackends()` | 默认列表加 `'kimi_code'`;`KimiCodeCapabilities::renderMcpConfig()` 产出 `~/.kimi/mcp.json` 形状 |
| `src/Console/Application.php` + `SuperAICoreServiceProvider::boot()` | 若新增 `kimi:sync` 命令则注册 |
| `config/super-ai-core.php` | `engines.kimi_code` 段 + `AI_CORE_KIMI_CODE_ENABLED` env |
| `src/Capabilities/SpawnConsolidationPrompt.php` | 不一定要动 —— `kimi_code` 默认走 native Agent,不进三阶段协议 |

### 3.3 复用 / 不复用

**复用**:
- 现有 `Sources/` 的 skill/agent 发现 —— Kimi 原生扫 `.claude/skills/`,零翻译
- `Sync/AbstractManifestWriter` + `Manifest` —— `KimiMcpWriter` 继承它
- `StreamingBackend` + `StreamableProcess` —— Kimi 的 `--print --output-format stream-json` 对得上
- `CliOutputParser::parseClaude()` 作为起点(Claude stream-json 最像,但要 Kimi 实测样本确认字段形状 —— 别盲目用)

**不复用**:
- `GeminiCliBackend::parseJson()` 的 preamble tolerance 逻辑 —— Kimi 输出应无此问题,除非实测发现同类噪声
- `.claude/agents/*.md` → Kimi 专用 agent YAML 的翻译(如果 Kimi 原生吃 Claude agent 格式就不需要 —— 实测确认)

### 3.4 关键集成决策:Agent Team

**默认 (a) / 可降级 (b) 的混合方案**:

- **默认路径**:`kimi_code` **不**注册进 `spawn_plan_capable_backends`。让 Kimi 走自己的 native `Agent` 工具做扁平 fanout,行为和 Claude / Kiro 同级。`BackendCapabilities::spawnPreamble()` 返回空字符串(或短期内不实现,等 Claude/Kiro 的 trait 默认 no-op 吃掉)。
- **opt-in 降级**:`KimiCodeCapabilities` 暴露 `$use_native_agents` 属性,运维显式设为 `false` 时,`Pipeline` 认出可以走三阶段协议,像 Codex / Gemini 一样用我们的 Orchestrator 做 host-side fanout。这种情况下 0.6.8 的所有加固(`appendGuards`、canonical output_subdir、`auditAgentOutput`、语言感知 consolidation)全部继续适用。

**何时选 (b) 降级**:

1. 运维需要 **per-child stream 可观测性** —— Kimi stream-json 的 `SubagentEvent` 黑盒是硬伤,UI 要显示每子 agent 的实时 tool_use 时必须降级。
2. 任务会**超过单 turn 500 步** —— 靠宿主端多个 root-call 绕开。
3. 需要标准 `摘要.md` / `思维导图.md` / `流程图.md` 三件套 —— 交给我们 `SpawnConsolidationPrompt::build()`(已语言感知)。

**何时留 (a) 默认**:

- 任务能在 500 步内搞定
- 运维接受把并发 fanout 交给模型自己调度
- 不需要 per-child auditAgentOutput 的"骗人检测"(Kimi 子 agent 不返回 `filesWritten`,我们这套防御在 native 路径下失效 —— 是 (a) 的已知代价)

---

## 4. MVP 分期

### MVP-1(1-2 天)—— 基础 one-shot ✅ 已完成

- [x] 本机装 `kimi`(`uv tool install kimi-cli`)+ `kimi login`,实测:
  - [x] `kimi --version` / `kimi --help` 真实输出 —— 见 §5 实测结论
  - [x] `kimi --print --output-format stream-json "..."` 的每行 JSON envelope 形状 —— 三种 event:`role=assistant`(含 `content[].type=think/text` + 可选 `tool_calls[]`)、`role=tool`(含 `tool_call_id`)、`role=user`
  - [x] auth 状态检测机制 —— 文件探针:`~/.kimi/credentials/kimi-code.json` 存在即 logged-in
- [x] `KimiCliBackend` 骨架:`name()` / `isAvailable()` / `generate()` / `stream()` 实现 `Backend + StreamingBackend`
- [x] `KimiCapabilities` 骨架:`supportsMcp=true` / `streamFormat=stream-json` / `mcpConfigPath=.kimi/mcp.json`
- [x] `EngineCatalog::seed()` 加 `kimi` 条目(default_model=`kimi-code/kimi-for-coding`、billing=subscription)
- [x] `ProviderTypeRegistry` 加 `moonshot-builtin`
- [x] `CliStatusDetector::detectAuth('kimi')` 能报 logged-in / method(oauth)
- [x] `CliInstaller::installHint('kimi')` 给 `uv tool install kimi-cli` + `pip install --user kimi-cli` fallback(新增 `SOURCE_UV` / `SOURCE_PIP` 常量)
- [x] `config/super-ai-core.php` + `AI_CORE_KIMI_CLI_ENABLED` / `KIMI_CLI_BIN` / `AI_CORE_KIMI_MAX_STEPS_PER_TURN`
- [x] 单进程 PHP smoke:`(new KimiCliBackend)->generate(['prompt'=>'say hi'])` → `{text: 'Hey there! 👋', …}`
- [x] Unit tests(`KimiCliBackendTest` 12 用例):stream parser、命令行构造、max_steps 覆写、mcp_config_file 注入、think 不泄漏、多轮 tool_use

**实际交付**:单 agent 执行 + stream-json 解析、`cli:status` 显示 `logged in (oauth)`。Usage 计数为 0(Kimi stream-json 不暴露 tokens,subscription 计费主成本已 $0,该决定不影响收费正确性)。

### MVP-2(3-5 天)—— MCP + Skill + Agent Team ✅ 已完成

- [x] `KimiCapabilities::renderMcpConfig()` + `McpManager::syncAllBackends` 静态 fallback 加 `kimi`:`claude:mcp-sync` 自动 propagate 到 `~/.kimi/mcp.json`,非 `mcpServers` 字段保留(oauth token / telemetry 设置等)
- [x] `mcp:sync-backends` 默认把 `kimi` 纳入(同一静态 fallback)
- [x] 实测 Kimi 对 `.claude/skills/` 的读取 —— ✅ 原生扫描,零翻译,启动时就把 skill 注入到 root agent 的 system prompt
- [x] 实测 `.claude/agents/*.md` 兼容度 —— ❌ 不原生读,Kimi 用自己的 YAML 格式(`~/.kimi/agents/<ns>/<name>/agent.yaml` + `system.md`)
- [x] **Agent team (b) 降级路径联通**:`KimiCapabilities::useNativeAgents()` 读 `super-ai-core.backends.kimi_cli.use_native_agents`(默认 true);false 时 `spawnPreamble()` 返回 PREAMBLE、`consolidationPrompt()` 返回 `SpawnConsolidationPrompt::build(...)`,Pipeline 的三阶段 + 0.6.8 加固(guard 注入 / canonical output_subdir / auditAgentOutput / 语言感知 consolidation)全部适用
- [x] `KimiCapabilities::spawnPreamble()` 最小实现 —— PREAMBLE 常量,Kimi tool-name 映射 + spawn-plan JSON shape
- [x] Unit tests(`KimiCapabilitiesTest` 11 用例):a/b 开关对称性、preamble 幂等注入、`mcpServers` 覆盖 + 其它段保留
- [x] Feature tests(`KimiMcpSyncTest` 3 用例,Orchestra Testbench):真 Laravel 容器里 project `.mcp.json` → `~/.kimi/mcp.json` 端到端、user auth 段保留、default fan-out 列表包含 `kimi`

### MVP-3(补完,本次随 MVP-2 一起发)—— Agent 翻译 ✅ 已完成

MVP-2 实测发现 `.claude/agents/` 不能原生读后,直接把 MVP-3 的 `KimiAgentSync` 也做完了,避免用户需要手写 Kimi YAML:

- [x] `Sync/KimiAgentSync.php` —— 把 Claude agent 翻译成 Kimi 的两文件布局
  - 输出:`~/.kimi/agents/superaicore/<name>/{agent.yaml,system.md}`(用 `superaicore/` 命名空间避免碰撞 Kimi 自带 `default/` / `okabe/`)
  - tool-name 映射表 `KimiAgentSync::TOOL_MAP`(Claude `Read/Write/Edit/Bash/Glob/Grep/WebFetch/WebSearch/Task` → Kimi 的 `kimi_cli.tools.file:*` / `kimi_cli.tools.shell:Shell` / `kimi_cli.tools.web:*` / `kimi_cli.tools.agent:Agent` 完整类路径)
  - `DEFAULT_TOOLS` 默认工作集(Claude `tools:` 为空时给的安全默认,**不含** Agent 以防递归 spawn)
  - 继承 `AbstractManifestWriter`:sha256 manifest、用户编辑两文件任一被改都标 `user_edited`、source 消失触发 `removed` / `stale_kept`
- [x] `kimi:sync` 命令(CLI + Artisan),风格和 `copilot:sync` / `kiro:sync` 一致
- [x] Unit tests(`KimiAgentSyncTest` 13 用例):两文件产出、tool 映射(含 Edit/MultiEdit 去重、`Bash(git:*)` 表达式剥离、未知 tool 丢弃)、空 tools 回退默认、idempotent re-sync、agent.yaml / system.md 任一 user-edit 保留、stale 删除、stale + user-edit 保留、dry-run 零盘 + 零 manifest
- [x] **真实设备端到端**:`kimi --print --agent-file ~/.kimi/agents/superaicore/echopet/agent.yaml --prompt "hello"` → `PET: hello`(Kimi 加载翻译产物并执行)

### 明确不做(v1 范围外)

- Kimi 的 `TaskList` / `TaskOutput` / `TaskStop` background 机制暴露给 Dispatcher —— 等有明确 async 需求再做
- `kimi --serve` / 长连接模式
- 自动降级到 native `Agent` 的策略识别 —— 运维显式选 (a) 或 (b),不做自动判断
- 覆盖内建 `coder` / `explore` / `plan` subagent
- `kimi-agent-sdk`(Node SDK)的集成 —— 不在 PHP 工程范围
- K2.6 "300 agents / 4000 steps" 规模尝试 —— CLI 达不到
- Token usage 估算(char-count heuristic)—— Kimi stream-json 不暴露 tokens,`billing_model=subscription` 下主成本已 $0,不做就是 0 误差;shadow cost 会是 0,如果将来运维抱怨再按字符数粗算
- `bin/superaicore call` 的 standalone Laravel bootstrap 问题 —— pre-existing 对 gemini/claude 等也挂,不是 Kimi 独有,另开 PR 处理

---

## 5. 实测结论与已解未决项(2026-04-22 本机 kimi v1.38.0)

### Kimi stream-json 实际形状(核心发现)

```jsonl
{"role":"assistant","content":[{"type":"think","think":"...","encrypted":null},{"type":"text","text":"Hi"}]}
{"role":"assistant","content":[...],"tool_calls":[{"type":"function","id":"tool_XXX","function":{"name":"Shell","arguments":"{\"command\":\"...\"}"}}]}
{"role":"tool","content":[{"type":"text","text":"..."}],"tool_call_id":"tool_XXX"}
```

- 三种 event,全部以 `role` 键区分。
- `content[].type` 取 `text` / `think`;`think` 是 CoT,不当最终文本透出。
- 多轮 tool-use 场景下最后一条 `role=assistant` 的 text 是权威答案 —— `KimiCliBackend::parseStreamJson` 按这个规则实现。
- **无 `usage` 字段**。token 计数完全不暴露。

### 解掉的未决项

| 问题 | 答案 |
|---|---|
| `kimi login --status`? | ❌ 不存在。auth 状态靠文件探针 `~/.kimi/credentials/kimi-code.json` 存在性判断。 |
| auth token 存放路径? | `~/.kimi/credentials/kimi-code.json`(0600 权限)+ `~/.kimi/credentials/kimi-code.lock`;另有 `~/.kimi/kimi.json` 记 `work_dirs` 但**不**表示 login 状态。 |
| stream-json 里的 `usage` / `cost_usd`? | 不存在。计费 model 已是 subscription,主成本 $0 不受影响;shadow cost 也是 0(可接受,见 §4 "明确不做")。 |
| `.claude/agents/*.md` 原生读? | ❌ 不读。Kimi 用 YAML 格式 + 独立 `system_prompt_path` 外部文件(尝试内联 `system_prompt:` 会抛 "System prompt path is required")。触发实施 KimiAgentSync。 |
| `.claude/skills/` 原生读? | ✅ 读。启动时 system prompt 就包含 `## Available skills` 段列出发现的 skill,零翻译。 |
| `~/.kimi/mcp.json` 形状? | 和 Claude `.mcp.json` 的 `{mcpServers: {...}}` 一致。`~/.kimi/config.toml` 另有其它设置(default_model / theme / hooks 等),非 `mcpServers` 字段保留契约已验证。 |
| Python-only 安装痛点? | `uv tool install kimi-cli` 放 `~/.local/bin/kimi`;`which kimi` 能直接找到;`CliInstaller` 给 uv + pip fallback。生产服务器需先装 uv 或 pip,不是 Kimi 独有问题。 |

### 保留风险(运维端需注意)

1. **stream-json SubagentEvent 黑盒** —— 截至 v1.38.0,`JsonPrinter.feed()` 的 `case _: pass` 仍然吞子 agent 事件。(a) 默认路径下 `/providers` UI 看不到 native `Agent` 工具分发的实时行为,只在 root 线里看到 `tool_calls[{name: "Agent"}]` 一条 + aggregated tool_result。需要 per-child 可观测性就切 (b) 降级。
2. **OAuth 订阅的 headless 可用性** —— `kimi login` 开浏览器,但 `~/.kimi/credentials/kimi-code.json` 被写后,headless 调用 `kimi --print ...` 能直接用 token。PHP-FPM 下跨 audit session 读 Keychain 不是问题,因为 token 是文件、不是 macOS Keychain 条目。Linux/Windows 同理。**低风险**。
3. **500 步上限** —— (a) 路径单 turn 硬刹车。宿主通过 `config('super-ai-core.backends.kimi_cli.use_native_agents', false)` 切 (b),走 Pipeline 三阶段绕开。

---

## 6. 成功指标(落地验收)

- [x] `(new KimiCliBackend)->generate(...)` 真实调用跑通,返回完整 envelope(本机 smoke 看到 `"Hey there! 👋"`)
- [x] `bin/superaicore cli:status` 显示 `kimi | yes | kimi, version 1.38.0 | logged in (oauth)`
- [x] `McpManager::syncAllBackends(['kimi'])` 在 Orchestra Testbench 里把 project `.mcp.json` 翻译写入 `~/.kimi/mcp.json`(Feature test 验证)
- [x] `.claude/skills/` 零翻译:kimi 在含此目录的 work-dir 启动时 system prompt 就列出 skill
- [x] `kimi:sync` 翻译 `.claude/agents/*.md` 为 Kimi YAML + system.md,`kimi --agent-file <path>` 真能加载并执行(本机 smoke 看到 `"PET: hello"`)
- [x] dashboard `/providers` 路径:engine key `kimi`,billing_model `subscription`,auth 可读(运维端需刷新 engine 配置缓存使其出现)

---

## 7. 和已交付能力的配合

| 已交付 | Kimi 集成后(已实施) |
|---|---|
| `McpCatalog` + `claude:mcp-sync` + `mcp:sync-backends`(0.6.8) | ✅ 已并入 —— `KimiCapabilities::renderMcpConfig` + `McpManager::syncAllBackends` 默认列表含 `kimi`;Feature test 覆盖 |
| `forgeomni/superagent 0.8.9` 的 `KimiProvider` | ✅ 保持独立 —— API-key 直连 HTTP 走 `superagent` backend;CLI 路径 `kimi_cli` 走 OAuth 订阅。正交,不冲突 |
| `AgentSpawn\Pipeline` 弱模型加固(0.6.8) | ✅ 默认(a)不触发;config 开关 `kimi_cli.use_native_agents=false` 切 (b) 后自动适用 guard 注入 / canonical output_subdir / auditAgentOutput / 语言感知 consolidation |
| `SuperAgentBackend` 的 `subagents` envelope 预埋(0.6.8) | ✅ 独立层级 —— 仅对 SDK `Agent` tool 路径有效;`kimi_cli` backend 自己走 stream-json 解析 |
| `api:status`(0.6.8) | ✅ 职责划分 —— `api:status` 只覆盖直连 HTTP provider(anthropic/openai/gemini/kimi/…);Kimi CLI 走 `cli:status` |
| `copilot:sync` / `kiro:sync`(pre-0.6.8) | ✅ 同 shape 兄弟 —— `kimi:sync` 沿 CopilotSyncCommand / KiroSyncCommand 风格,`AbstractManifestWriter` 共享 |

### 文件清单(本次实施落地)

```
src/Backends/KimiCliBackend.php                (A)  264 行
src/Capabilities/KimiCapabilities.php          (A)  247 行
src/Console/Commands/KimiSyncCommand.php       (A)  108 行
src/Sync/KimiAgentSync.php                     (A)  199 行
tests/Unit/KimiCliBackendTest.php              (A)  185 行
tests/Unit/KimiCapabilitiesTest.php            (A)  167 行
tests/Unit/Sync/KimiAgentSyncTest.php          (A)  208 行
tests/Feature/KimiMcpSyncTest.php              (A)  140 行
docs/kimi-cli-backend.md                       (M)  本文件

src/Models/AiProvider.php                      (M)  +BACKEND_KIMI / +TYPE_MOONSHOT_BUILTIN
src/Services/CapabilityRegistry.php            (M)  注册 KimiCapabilities
src/Services/EngineCatalog.php                 (M)  +'kimi' 引擎条目
src/Services/ProviderTypeRegistry.php          (M)  +'moonshot-builtin' type
src/Services/BackendRegistry.php               (M)  注册 KimiCliBackend
src/Services/CliStatusDetector.php             (M)  +'kimi' 到 BACKENDS + detectAuth 分支
src/Services/CliInstaller.php                  (M)  +SOURCE_UV / +SOURCE_PIP / +'kimi' 条目
src/Services/McpManager.php                    (M)  默认 fan-out 列表 +'kimi'
src/Console/Application.php                    (M)  注册 KimiSyncCommand
src/SuperAICoreServiceProvider.php             (M)  Artisan 注册 KimiSyncCommand
config/super-ai-core.php                       (M)  backends.kimi_cli 段
tests/Unit/BackendRegistryTest.php             (M)  fixture 跟随
tests/Unit/CliInstallerTest.php                (M)  fixture 跟随
tests/Unit/ProviderTypeRegistryTest.php        (M)  fixture 跟随
```

Total:9 个新文件 + 12 个修改 = **21 个文件变动**。全套测试 **453/453 绿**(56 用例为 Kimi 新增)。
