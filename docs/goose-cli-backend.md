# Design: `goose_cli` Backend

**状态**：设计中，Copilot 之后的下一个待实现
**日期**：2026-04-18
**目标版本**：待定（保持当前 0.5.6 基线，不自动 bump）
**相关文档**：`docs/copilot-cli-backend.md`、`docs/ai-rules-spec-analysis.md`

---

## 1. 背景与决策

[Goose](https://github.com/block/goose) 是 Block 开源的 on-machine AI agent，2025-01 GA，2025-12 连同 Anthropic 的 MCP 和 OpenAI 的 AGENTS.md 一起捐给 **Linux Foundation Agentic AI Foundation (AAIF)**。其核心设计哲学：**"Extensions = MCP servers"**，一切扩展都通过 MCP 协议。

### 为什么做 Goose（高优先级）

在所有评估过的候选 CLI 中，Goose 对 SuperAICore 的架构契合度**最高**：

1. **MCP-native**：Goose 把 MCP 提到一等概念，这和 SuperAICore 的 MCP manager 天然对齐
2. **开源 + 中立治理**：Linux Foundation AAIF 监护，不存在厂商锁定风险（对比 Claude/Copilot/Gemini/Codex 都是厂商 CLI）
3. **Headless 成熟**：`--text` 模式 + `goose serve` 后台服务
4. **Skill 格式兼容**：SKILL.md + YAML frontmatter（`name`/`description`），与 Claude / Copilot 同构
5. **AGENTS.md 支持**：与 Copilot 共享上下文约定

优先级排在 Copilot 之后，两者做完 SuperAICore 会从 "四引擎 + CLI 偏厂商" 变成 "六引擎 + 覆盖开源栈"。

---

## 2. Goose 能力清单

### 2.1 非交互调用

- `--text` 模式：2026 加入的独立 headless flag，清洁输出
- `goose serve`：后台服务，适合长连接场景
- Headless 环境下设 `GOOSE_DISABLE_KEYRING=true`，凭证降级到文件存储

**MVP-1 前需实测确认**：
- `goose run -t "<prompt>"` 的准确 flag（`-t`/`--text` 是否等价）
- 单次执行 prompt 的最简命令形态
- 输出格式：纯文本 or 支持 JSON

### 2.2 认证与 Provider

- 通过 `goose configure` 交互设定 provider
- 凭证默认入系统 keyring（macOS/Windows/Linux libsecret）
- 降级：`GOOSE_DISABLE_KEYRING=true` → 文件存储
- 支持 provider：OpenAI、Anthropic、Google、OpenRouter、Databricks、AWS Bedrock、Ollama（本地）等

**Provider 模型（SuperAICore 视角）**：

| Provider | 实现 |
|---|---|
| `builtin` | 委托 `goose configure` 的配置，SuperAICore 只读运行时状态，不管凭证 |

和 Claude/Copilot/Gemini CLI 的 `builtin` 模式一致。

### 2.3 配置文件

- 主配置：`~/.config/goose/config.yaml`（YAML）
- 支持 `XDG_CONFIG_HOME` 覆盖
- Extensions / MCP servers 在同文件声明

### 2.4 Extensions（= MCP Servers）

- 70+ 一方 extensions 打包
- 兼容任意 3000+ 社区 MCP server
- 传输：stdio、HTTP、SSE
- Docker 场景有 `mcp-gateway` 模式

### 2.5 Skills

SKILL.md + YAML frontmatter，字段 `name`（必填）、`description`（必填）。

**关键待测**：Goose 的 skill 扫描路径是什么？文档明说支持项目级 + 用户级 skill，但路径未在调研中完全确认。推测为：

- 项目级：`.goose/skills/`（可能）
- 用户级：`~/.config/goose/skills/`（可能）
- 可能也扫描 `.agents/skills/` —— 因为 Block 自己推广跨 agent 目录约定

MVP-2 前需本机验证实际路径。

### 2.6 Agents：Subagents vs Recipes

Goose 有**两套**代理机制，与 Claude/Copilot 的 `.claude/agents/*.md` 不同：

| 机制 | 定位 | 存储 |
|---|---|---|
| **Subagents** | 运行时临时代理，prompt 里自然语言创建，一次性 | 无文件 |
| **Recipes / Subrecipes** | 预写、可复用、参数化工作流 | YAML（subrecipe 强制 YAML；parent 可 YAML/JSON）|

Recipe 字段：
- `parameters`（类型化、可校验）
- `extensions`（要启用哪些 MCP）
- `provider`/`model`（可独立于主 agent）
- `prompt` 或 `instructions`
- 模板支持（dynamic parameter injection）

**翻译映射**（Claude agent → Goose recipe）：

| Claude 字段 | Goose recipe 字段 | 备注 |
|---|---|---|
| `name` | recipe `name` | 直接 |
| `description` | recipe `description` | 直接 |
| `tools`（列表）| `extensions`（列表）| 需要 tool → extension 映射表 |
| `model` | `model` | 直接，Goose 支持 per-recipe 模型 |
| 正文 body | `prompt` 或 `instructions` | 直接 |

Goose recipe 的 `parameters` 是 Claude agent 格式没有的能力 —— translator 现阶段不生成 parameters，手写覆盖场景留给用户。

### 2.7 AGENTS.md / .goosehints

Goose 自动加载：
- `AGENTS.md`（与 Copilot/OpenAI 共享约定）
- `.goosehints`（Goose 专属）
- 其他 project context 文件

**SuperAICore 应对**：skill runner / agent runner preamble 注入时，若存在 `AGENTS.md` 即自动纳入上下文，与 `.claude/CLAUDE.md` 并列支持（详见 `docs/ai-rules-spec-analysis.md` 决定）。

---

## 3. 对 SuperAICore 现有架构的影响

### 3.1 新增文件

```
src/Backends/GooseCliBackend.php         # 新 backend 类
src/Sync/GooseRecipeSync.php             # Claude agent → Goose recipe YAML
src/Sync/GooseMcpSync.php                # MCP 配置同步到 goose config.yaml
src/Sync/GooseSkillSync.php              # 若 Goose 不扫 .claude/skills/，复制到 Goose skills 目录
src/Console/GooseSyncCommand.php         # goose:sync CLI 入口
src/Translator/GooseToolToExtension.php  # Claude tool 名 → Goose extension 名映射
```

### 3.2 修改点

| 文件 | 修改 |
|---|---|
| `src/SuperAICoreServiceProvider.php` | 注册 `GooseCliBackend` |
| Dispatcher registry | `list-backends` 显示 `goose_cli` |
| `config/super-ai-core.php` | `backends.goose_cli` 段：`enabled` / `binary_path` / `timeout` / `default_model` |
| `.env.example` / INSTALL | `AI_CORE_GOOSE_CLI_ENABLED=false` |
| `src/Runner/SkillRunner.php` | `goose` 分支：若 Goose 扫 `.claude/skills/` 则透传，否则先触发 `goose:sync --skills` |
| `src/Runner/AgentRunner.php` | `goose` 分支：要求已跑 `goose:sync --agents` 生成 recipe |
| Pricing migration | 若尚未加 `billing_model` 列（Copilot 设计已要求），此处继续沿用；Goose 走 provider 账单，非订阅 |
| MCP manager Blade UI | Goose 配置目标加入 sync 选项列表 |

### 3.3 复用 / 不复用

**复用**：
- 现有 `Sources/` 下 skill/agent 发现逻辑（Goose 和 Claude/Copilot 都从 `.claude/` 起家）
- MCP manager 的中央数据（只是加一个输出 target）
- Symfony\Process 调用封装

**不复用（避免过早抽象）**：
- `Sync/` 下已有的 `GeminiSync`、将来的 `CopilotSync`，各写各的。三个 translator 跑起来后若结构确实趋同再抽 `AbstractBackendSync`
- `block/ai-rules` schema（见 `docs/ai-rules-spec-analysis.md`，明确拒绝采纳）

---

## 4. MVP 分期

### MVP-1（目标 1-2 天）

- [ ] 本机实测：`goose` binary 的 headless flag（`--text` / `-t`）、单次 prompt 执行命令、输出格式
- [ ] `GooseCliBackend` 骨架：`name()` / `isAvailable()` / `call()`
- [ ] `isAvailable()`：`which goose` + 读 `~/.config/goose/config.yaml` 判断是否已配过 provider
- [ ] `call()`：`goose run --text "<prompt>"`（命令形态以实测为准），通过 `Symfony\Process`
- [ ] Dispatcher 注册 + ServiceProvider 绑定
- [ ] `list-backends` 能列出 `goose_cli` 及状态
- [ ] Config + env：`AI_CORE_GOOSE_CLI_ENABLED`、`AI_CORE_GOOSE_CLI_BINARY`、`AI_CORE_GOOSE_CLI_DEFAULT_MODEL`
- [ ] 如果 Goose 原生扫 `.claude/skills/`：`skill:run --backend=goose` 零翻译透传。否则推迟到 MVP-2
- [ ] Feature test：mock Process，断言命令行 + stdout 解析
- [ ] README / INSTALL / engine 列表更新

**交付后可用范围**：`./vendor/bin/superaicore call "..." --backend=goose_cli`。skill/agent 的 Goose 执行等 MVP-2。

### MVP-2（目标 3-4 天）

- [ ] `goose:sync --mcp`：把 SuperAICore MCP 配置 merge 到 `~/.config/goose/config.yaml`（**注意**：YAML merge 要保留用户已有段，同 Claude MCP sync 的策略）
- [ ] `goose:sync --skills`（条件性）：若实测 Goose 不扫 `.claude/skills/`，复制到 Goose skill 目录
- [ ] `goose:sync --agents`：`.claude/agents/*.md` → Goose recipe YAML
  - 字段重排：`name/description` 直接，`tools` → `extensions`（需 tool→extension 映射表），`model` 直接
  - 正文 body → recipe `prompt` 字段
  - 不自动生成 `parameters`（留给用户手写）
- [ ] `agent:run --backend=goose`：调用 `goose run --recipe <file>.yaml`（具体 flag 以实测为准）
- [ ] `GooseToolToExtension` 映射表：Claude 常见 tool（`Read`/`Write`/`Bash`/`Grep` 等）→ 对应 Goose extension；未映射的 tool 保留但发 warning
- [ ] Usage 日志：若 Goose 输出 token 计数则解析，否则仅记时长
- [ ] `AGENTS.md` 通用支持（与 Copilot 共享）：在 skill/agent runner preamble 里加载
- [ ] Feature test：translator 产出正确 YAML、MCP merge 不覆盖用户配置

### 明确不做（v1 范围外）

- Goose 的 `serve` 后台模式暴露（Dispatcher 只跑 one-shot）
- Subagent / 多代理并行的显式暴露
- Recipe 的 `parameters` 自动生成（留给用户手写）
- Docker mcp-gateway 集成
- Goose TUI 调用（我们只走 headless）

---

## 5. 关键风险与未决项

| 项 | 风险 | 缓解 |
|---|---|---|
| Goose headless flag 确切形态 | `--text` vs 其他？需要本机验证 | MVP-1 开工第一步做 `goose --help` 核对 |
| Goose 是否扫 `.claude/skills/` | 文档未明确，决定透传 vs sync 策略 | MVP-1 实测 → 决定 MVP-2 是否做 skill sync |
| Tool → Extension 映射完整度 | 有些 Claude tool（如内置 `Task`）在 Goose 无对应 extension | 未映射的发 warning 不 fail；用户可手动补 extension |
| YAML merge 冲突 | MCP sync 改用户配置文件要非破坏 | 复用已有 `ClaudeMcpSync` 的 merge 策略，加单元测试覆盖"用户预存配置" |
| 订阅 vs 按量 | Goose 用 user provider key，走 token 计费 —— 和 Copilot 订阅不同 | `billing_model` 列标 `usage`，pricing 从对应 provider 复制 |
| 治理变化 | Linux Foundation AAIF 新成立，治理稳定性待观察 | 低风险项，继续跟踪 AAIF 动态 |

---

## 6. 验收标准

MVP-1 完成的标志：

- `./vendor/bin/superaicore list-backends` 显示 `goose_cli` 及状态
- 本机 `goose configure` 后，`call "Hello" --backend=goose_cli` 返回响应
- 未安装 / 未配 provider 时，`isAvailable()` 返回 false，提示清晰

MVP-2 完成的标志：

- `goose:sync --mcp` 把 SuperAICore MCP 配置正确 merge 进 `~/.config/goose/config.yaml`，不覆盖用户已有段
- `goose:sync --agents` 产出的 recipe YAML 能被 `goose run --recipe` 正常执行
- `agent:run <name> --backend=goose` 走完整流程
- `AGENTS.md` 若存在，被 preamble 纳入上下文
- Goose usage 记录进 `ai_usage_logs`（token 或时长）

---

## 7. 参考资料

- [Goose on GitHub (block/goose)](https://github.com/block/goose)
- [Goose docs](https://goose-docs.ai/)
- [Goose Configuration Files](https://goose-docs.ai/docs/guides/config-files/)
- [Goose CLI reference (DeepWiki)](https://deepwiki.com/block/goose/3.2-command-line-interface)
- [Subagents vs Subrecipes](https://block.github.io/goose/blog/2025/09/26/subagents-vs-subrecipes/)
- [Goose Context Engineering (AGENTS.md / .goosehints)](https://block.github.io/goose/docs/guides/context-engineering/)
- [Goose 🤝 FastMCP](https://gofastmcp.com/integrations/goose)
- [Block contributes Goose to Linux Foundation AAIF](https://block.xyz/inside/block-open-source-introduces-codename-goose)
- [Advent of AI 2025 Day 14 - Agent Skills](https://www.nickyt.co/blog/advent-of-ai-2025-day-14-agent-skills-4d48/)
- [block/agent-skills marketplace](https://github.com/block/agent-skills)
