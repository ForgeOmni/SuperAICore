# Design: `kimi_code` Backend

**状态**：设计中,0.6.8 之后的待实现候选
**日期**:2026-04-22
**目标版本**:保持当前 0.6.8 基线,不自动 bump
**相关文档**:`docs/copilot-cli-backend.md`、`docs/spawn-plan-protocol.md`、`docs/streaming-backends.md`、`docs/mcp-sync.md`

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

### MVP-1(1-2 天)—— 基础 one-shot

- [ ] 本机装 `kimi`(`uv tool install kimi-cli`)+ `kimi login`,实测:
  - `kimi --version` / `kimi --help` 真实输出
  - `kimi --print --output-format stream-json "..."` 的每行 JSON envelope 形状(和 Claude 比对差异)
  - auth 状态检测机制:`kimi login --status`?文件探针?
- [ ] `KimiCodeCliBackend` 骨架:`name()` / `isAvailable()` / `generate()` / `stream()` 实现 `Backend + StreamingBackend`
- [ ] `KimiCodeCapabilities` 骨架:`supportsMcp=true` / `supportsSystemPrompts=true` / `spawnPreamble=''` / `mcpConfigPath=~/.kimi/mcp.json`
- [ ] `EngineCatalog::seed()` 加 `kimi_code` 条目
- [ ] `ProviderTypeRegistry` 加 `moonshot-builtin`
- [ ] `CliStatusDetector::detectAuth('kimi')` 能报 logged-in / method
- [ ] `CliInstaller::installHint('kimi_code')` 给 uv + pip fallback
- [ ] `config/super-ai-core.php` + `AI_CORE_KIMI_CODE_ENABLED`
- [ ] Dispatcher `call` 能走通:`bin/superaicore call "hello" --backend=kimi_code`
- [ ] Unit tests:stream parser / 命令行构造 / provider 接入

**交付后可用**:单 agent 执行 + stream-json 解析 + usage 记账。Agent team 先走 native(步骤 2 里把 `use_native_agents` 锁为 `true`),不测试三阶段 fallback。

### MVP-2(3-5 天)—— MCP + Skill + Agent Team

- [ ] `Sync/KimiMcpWriter` + 接入 `McpCatalog::syncAllBackends`:`claude:mcp-sync` 自动 propagate 到 `~/.kimi/mcp.json`
- [ ] `mcp:sync-backends` 默认把 `kimi_code` 纳入
- [ ] 实测 Kimi 对 `.claude/skills/` 是否真能读 —— 如 README 所述,skill:run --backend=kimi 零翻译透传
- [ ] 实测 `.claude/agents/*.md` 兼容度;若不兼容,建 `KimiAgentSync`(YAML frontmatter → Kimi agent 定义 —— 具体 shape 待实测)
- [ ] **Agent team (b) 降级路径联通**:`KimiCodeCapabilities::$use_native_agents = false` 时 `Pipeline` 认得走三阶段,`SpawnConsolidationPrompt` 的语言感知 / auditAgentOutput / guard 注入全部适用
- [ ] `KimiCodeCapabilities::spawnPreamble()` 最小实现(仅在 (b) 路径用)
- [ ] Feature test:
  - MCP merge 不覆盖用户 `~/.kimi/mcp.json` 里的手写 server
  - `agent:run --backend=kimi` 在 native 路径下正常分发(mock Process)
  - 降级路径下 Pipeline 三阶段完整跑通(复用 GeminiCli / CodexCli 的测试 fixture)

### 明确不做(v1 范围外)

- Kimi 的 `TaskList` / `TaskOutput` / `TaskStop` background 机制暴露给 Dispatcher —— 等有明确 async 需求再做
- `kimi --serve` / 长连接模式
- 自动降级到 native `Agent` 的策略识别 —— 运维显式选 (a) 或 (b),不做自动判断
- 覆盖内建 `coder` / `explore` / `plan` subagent
- `kimi-agent-sdk`(Node SDK)的集成 —— 不在 PHP 工程范围
- K2.6 "300 agents / 4000 steps" 规模尝试 —— CLI 达不到

---

## 5. 关键风险与未决项

### 风险

1. **stream-json SubagentEvent 黑盒** —— `JsonPrinter.feed()` 的 `case _: pass` 是 2026-04 调研时的状态;MVP-1 先核实最新版本,如果 Moonshot 后续修了,`/providers` 实时可观测性就不再是 (a) 路径的代价。
2. **OAuth 订阅的 headless 可用性** —— `kimi login` 开浏览器,PHP-FPM 的 web worker 怎么拿到 token?参考 Claude `builtin` 的 Keychain fallback 策略(macOS `security find-generic-password`),但 Kimi 把 token 存哪儿需要实测 `~/.kimi/auth.json` 之类。
3. **Python-only 安装路径** —— 生产服务器可能没 `uv` / 没 Python 3.11+;`cli:install` 需要诚实报错而不是隐式失败。
4. **`.claude/agents/*.md` 兼容度**未完全确认 —— MVP-2 前必须本机验证,否则要加一个 `KimiAgentSync` 翻译层。
5. **500 步上限** —— 在 (a) 默认路径下,复杂任务可能硬刹车。需要在 `agent:run` 的文档里标注,并提示(b)降级路径。

### 未决项(MVP-1 前解)

- `kimi --print` 的 exit code 语义:0 是真成功,还是"被 kill 但上报成功"?
- `stream-json` 里的 `usage` / `cost_usd` 字段是否存在?若不存在,走 `SuperAgent\Providers\ModelCatalog::pricing()` 兜底
- `.claude/agents/*.md` 能不能直接跑?
- MCP `~/.kimi/mcp.json` 是否完全兼容 Claude `.mcp.json` 形状(应该一致,但确认)

### 实测清单(MVP-1 开始时)

```bash
# 环境
uv tool install kimi-cli
kimi login

# 必须验证
kimi --version
kimi --help
kimi --print "say hi" --output-format stream-json   # 核心
kimi mcp list                                         # 看现有配置
ls ~/.kimi/                                           # 配置目录形状
cat ~/.kimi/mcp.json                                  # 如存在,比对 .mcp.json 格式
kimi --print "list .claude/skills you see"           # skill 扫描行为
```

---

## 6. 成功指标

- `bin/superaicore call "..." --backend=kimi_code` 在 MVP-1 后 one-shot 跑通
- `claude:mcp-sync` 默认把 project `.mcp.json` 推到 `~/.kimi/mcp.json`,MCP 服务器在 Kimi 里立即可用
- `skill:run <skill> --backend=kimi` 零翻译 pass-through
- `agent:run <agent> --backend=kimi` 在 native 路径跑通;降级路径和 Codex/Gemini 一致复用现有三阶段
- dashboard `/providers` 页面显示 `kimi_code` 引擎卡片,billing_model 订阅态,auth 状态可读

---

## 7. 和已交付能力的配合

| 已交付 | Kimi 集成后 |
|---|---|
| `McpCatalog` + `claude:mcp-sync` + `mcp:sync-backends`(0.6.8) | Kimi 自动被纳入 backend fan-out 列表 |
| `SuperAgent 0.8.8 KimiProvider` | 保持独立,走 API-key 直连 HTTP 路径;和 CLI 路径正交 |
| `AgentSpawn\Pipeline` 弱模型加固(0.6.8) | 默认不触发(native 路径);降级 (b) 时全部适用 |
| `SuperAgentBackend` 的 `subagents` envelope 预埋(0.6.8) | 仅对 SDK 路径有效;CLI 路径单独走 stream-json |
| `api:status`(0.6.8) | 不覆盖 Kimi CLI —— CLI 走 `cli:status` 反之亦然 |
