<?php

namespace SuperAICore\AgentSpawn;

/**
 * Structured plan emitted by a backend that can't natively spawn sub-agents.
 * The preamble instructs the model to write this file (`_spawn_plan.json`)
 * in the run's output directory as its first-phase output.
 *
 * Preferred shape (minimal — the model doesn't have to JSON-escape any
 * multi-line agent markdown, which routinely trips smaller models like
 * Gemini Flash):
 *
 * {
 *   "version": 1,
 *   "concurrency": 4,
 *   "agents": [
 *     { "name": "cto-vogels",   "task_prompt": "...", "output_subdir": "cto-vogels" },
 *     { "name": "ceo-bezos",    "task_prompt": "...", "output_subdir": "ceo-bezos"  }
 *   ]
 * }
 *
 * The host resolves each agent's `system_prompt` by reading
 * `<agentsDir>/<name>.md` (typically `<projectRoot>/.claude/agents/`)
 * when {@see fromFile} is called with an `$agentsDir`. `output_subdir`
 * defaults to `name` when omitted.
 *
 * Legacy shape with an inline `system_prompt` string is still accepted —
 * useful for hosts that don't ship role files on disk — but discouraged
 * because embedding multi-line YAML-frontmatter markdown inside JSON
 * causes unescaped-quote / unescaped-newline parse failures.
 */
class SpawnPlan
{
    public function __construct(
        public readonly array $agents,
        public readonly int $concurrency = 4,
    ) {}

    /**
     * @param  string       $path        Absolute path to `_spawn_plan.json`.
     * @param  string|null  $agentsDir   Absolute path to the host's agents
     *         directory (e.g. `<projectRoot>/.claude/agents`). When a plan
     *         entry omits `system_prompt` (the preferred shape), we resolve
     *         it by reading `<agentsDir>/<name>.md`. Null keeps the legacy
     *         "must-be-embedded" behavior.
     */
    public static function fromFile(string $path, ?string $agentsDir = null): ?self
    {
        if (!is_file($path) || !is_readable($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;

        $json = json_decode($raw, true);
        if ($json === null) {
            // Models (gemini-cli especially) sometimes emit raw \n / \t / \r
            // inside string values, which JSON spec forbids. Re-escape those
            // control chars and try again before giving up.
            $cleaned = self::reescapeControlCharsInJsonStrings($raw);
            $json = json_decode($cleaned, true);
        }

        // Last-ditch recovery: the model embedded agent markdown with
        // unescaped double-quotes (e.g. YAML frontmatter `description: "..."`),
        // which no amount of control-char re-escaping can fix — the JSON
        // parser has no way to tell which `"` is meant as the string
        // terminator. Salvage just the `name` fields with regex and let
        // the caller reconstruct `system_prompt` from disk by name.
        if (!is_array($json) || empty($json['agents']) || !is_array($json['agents'])) {
            $salvage = self::salvageNamesOnly($raw);
            if ($salvage === null) return null;
            $json = $salvage;
        }

        $agents = [];
        foreach ($json['agents'] as $a) {
            if (!is_array($a) || empty($a['name'])) continue;

            $systemPrompt = (string) ($a['system_prompt'] ?? '');
            if ($systemPrompt === '' && $agentsDir !== null) {
                $systemPrompt = self::loadAgentDefinition($agentsDir, (string) $a['name']);
            }

            // task_prompt is strongly preferred but not strictly required —
            // if we had to salvage the plan, we still want the child to run
            // against the role definition so the consolidation pass has
            // something to read. Pass '' when absent; ChildRunner turns
            // that into "just the system_prompt" with a trailing separator.
            $taskPrompt = (string) ($a['task_prompt'] ?? '');

            // Host-side guard injection (RUN 68 fix, 2026-04-22). Weak
            // models (notably Gemini Flash) ignore backend-preamble guard
            // clauses when emitting task_prompt — we saw regional-khanna
            // fabricate sibling-role sub-directories, all CSVs ship in
            // English, `_signals/*.md` ship in English, children write
            // .py helper scripts. Appending these clauses in the host
            // guarantees they reach every child regardless of which model
            // composed the plan.
            $taskPrompt = self::appendGuards($taskPrompt, (string) $a['name']);

            // Force canonical English output_subdir (RUN 70 fix, 2026-04-22).
            // Gemini Flash sometimes emits localized output_subdir (e.g.
            // `首席执行官` instead of `ceo-bezos`). That breaks two things:
            //   1. Consolidation re-call: Flash hallucinates "no output
            //      files found" when walking non-ASCII paths, even though
            //      the directories exist — consolidation writes
            //      `Error_No_Agent_Outputs_Found.md` instead of `摘要.md`.
            //   2. Orchestrator::auditAgentOutput: it walks
            //      `$outputRoot/<name>/`, which would be empty while the
            //      real writes are at `$outputRoot/<localized>/`, giving
            //      a false clean bill of health.
            // Children write to whatever absolute path is in their
            // task_prompt (which the preamble + host-injected guards
            // already pin to `$outputRoot/<canonical>/`), so we just
            // discard the model's output_subdir preference.
            $outputSubdir = (string) $a['name'];

            $agents[] = [
                'name' => (string) $a['name'],
                'system_prompt' => $systemPrompt,
                'task_prompt' => $taskPrompt,
                'output_subdir' => $outputSubdir,
            ];
        }
        if (empty($agents)) return null;

        return new self(
            agents: $agents,
            concurrency: max(1, min(8, (int) ($json['concurrency'] ?? 4))),
        );
    }

    /**
     * Marker block used to detect already-injected guards so calling
     * fromFile() twice on the same plan doesn't double-append. Also serves
     * as the prefix for the language-appropriate guard text.
     */
    const GUARD_MARKER = '## [SuperAICore host-injected per-agent guard]';

    /**
     * Guard text templates — one per supported language. The detection
     * heuristic in {@see appendGuards} picks Chinese when the task_prompt
     * contains any CJK characters (above 0x4E00), otherwise English. Both
     * versions convey the same 4 rules so downstream auditing
     * ({@see Orchestrator::auditAgentOutput}) catches the same violations
     * regardless of which version was injected.
     */
    const GUARDS_ZH = <<<'TXT'

## [SuperAICore host-injected per-agent guard]

以下规则宿主强制注入，不可忽略。你是 `__AGENT_NAME__`，专注你自己的分析。

- **角色边界**：只写自己 `output_subdir` 下的文件，不要创建其它角色子目录（ceo/ cfo/ marketing/ 等），不要替别人写报告。
- **不写整合**：`摘要.md` / `思维导图.md` / `流程图.md` 由宿主之后的 consolidation 统一写，你不碰。
- **语言一致（包含文件名）**：markdown 正文、section 标题、CSV 表头与非专有名词、文件名——**全部用中文**。不要起 `financial_analysis_report.md` / `canada_regional_data.csv` / `chart-1-market.png` 这类英文文件名；必须叫 `财务分析报告.md` / `加拿大区域数据.csv` / `市场份额-图表1.png`。公司名/URL/数字可保留原文。
- **扩展名只有** `.md` / `.csv` / `.png`。图表直接渲染成 PNG 用 write 工具保存。
- **IAP 信号板路径**：如果你要写 Findings Board，路径**必须**是 `<你的 output_subdir>/_signals/__AGENT_NAME__.md`（先建 `_signals/` 子目录，再写以你的 agent 名命名的 `.md`）。不要直接在 output_subdir 下写 `_signal_xxx.md`。
- **丰富度要求**：markdown 报告**鼓励**多个 section、逐层展开、附多张 PNG 图表（2–5 张），把数据洞察可视化。预算是"1 份 md + 1 份 csv + 任意多张 png"，不是"1 页纸"。CSV 的行数越具体越有用。
- **不要为工具失败道歉**：如果 `mcp_serp_search` / `google_web_search` 等工具报错或限流，**换其它工具重试**（google_web_search 通常可用，web_fetch 也可替代）。**不要**在报告开头写"由于搜索工具遇到技术限制……本报告基于有限结果"这类元信息道歉 —— 那会污染分析正文。如果**确实**有数据覆盖不足，简短写进报告末尾的「方法论 / 数据局限性」小节即可。
TXT;

    const GUARDS_EN = <<<'TXT'

## [SuperAICore host-injected per-agent guard]

Host-injected rules, non-negotiable. You are `__AGENT_NAME__`, focused on your own analysis.

- **Stay in your lane**: only files under your own `output_subdir`; no sibling-role sub-dirs (ceo/ cfo/ marketing/…), no writing for other agents.
- **No consolidation**: `summary.md` / `mindmap.md` / `flowchart.md` are the consolidator pass's job, not yours.
- **Language uniformity (filenames too)**: markdown body, section headings, CSV headers and non-proper-noun cells, AND filenames — all in `$LANGUAGE`. Proper nouns, URLs, numbers stay original.
- **Extensions: `.md` / `.csv` / `.png` only.** Render charts directly to PNG via the write tool.
- **IAP signal board path**: if you write a Findings Board file, the path MUST be `<your output_subdir>/_signals/__AGENT_NAME__.md` (mkdir `_signals/` first, then the `.md` named after your agent). Do NOT write `_signal_xxx.md` at the top of your output_subdir.
- **Be rich**: the markdown report is **encouraged** to have multiple sections and multiple (2–5) PNG charts visualizing the data. Budget is "1 md + 1 csv + any number of png", not "one page". Detailed CSV rows beat sparse ones.
- **Don't apologize for tool failures**: if `mcp_serp_search` / `google_web_search` / similar error out or hit rate limits, **switch to another tool and retry** (google_web_search is usually available; web_fetch is an alternative). Do NOT open your report with a paragraph like "Due to search tool technical limitations, this report is based on limited results" — that contaminates the analysis. If coverage is genuinely thin, add a short bullet to the end-of-report Methodology / Data Limitations section and move on.
TXT;

    /**
     * Append the language-appropriate guard block to an agent's
     * task_prompt. Idempotent — skips if the marker is already present.
     * Language detection is a single CJK regex: if any char ≥ 0x4E00
     * (CJK Unified Ideographs start) appears in the task_prompt, we treat
     * the run as Chinese. That lines up with how `$LANGUAGE=zh-CN` is
     * threaded through the skill (every existing zh-CN task_prompt
     * contains the Chinese task description itself).
     *
     * Also strips any inline "CRITICAL OUTPUT RULE: ..." sentence the
     * first-pass model embedded. ChildRunner appends a fresh, authoritative
     * version built from `$outputRoot/$output_subdir` — having both in the
     * prompt creates path conflicts when the model emitted a localized
     * `output_subdir` that the host then overrode (RUN 70 fix).
     */
    public static function appendGuards(string $taskPrompt, string $agentName): string
    {
        if (str_contains($taskPrompt, self::GUARD_MARKER)) {
            return $taskPrompt;
        }
        // Strip any "CRITICAL OUTPUT RULE:" clause up to the next blank line
        // or end of string. Both single-sentence and multi-sentence forms
        // observed in the wild.
        $taskPrompt = preg_replace(
            '/\s*CRITICAL OUTPUT RULE:.*?(?=\n\s*\n|\z)/su',
            '',
            $taskPrompt
        ) ?? $taskPrompt;

        $isChinese = preg_match('/[\x{4E00}-\x{9FFF}]/u', $taskPrompt) === 1;
        $template = $isChinese ? self::GUARDS_ZH : self::GUARDS_EN;
        $block = str_replace('__AGENT_NAME__', $agentName, $template);
        return rtrim($taskPrompt) . "\n" . $block;
    }

    /**
     * Read `<agentsDir>/<name>.md`. Returns the raw markdown (frontmatter
     * + body). Missing / unreadable files resolve to '' so the child still
     * runs against just the task_prompt — that's better than the whole
     * run bailing because one role file is missing.
     */
    protected static function loadAgentDefinition(string $agentsDir, string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
        if ($safe === '') return '';
        $path = rtrim($agentsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe . '.md';
        if (!is_file($path) || !is_readable($path)) return '';
        return (string) @file_get_contents($path);
    }

    /**
     * Pull just the agent `name`s out of a malformed plan with a regex.
     * Used when `json_decode` can't recover — e.g. the model embedded
     * markdown with unescaped quotes. Returns a minimal plan-shaped array
     * so `fromFile()` can resolve system_prompt from disk by name.
     *
     * @return array{agents:array<int,array{name:string}>,concurrency:int}|null
     */
    protected static function salvageNamesOnly(string $raw): ?array
    {
        if (!preg_match_all('/"name"\s*:\s*"([A-Za-z0-9._-]+)"/', $raw, $m)) {
            return null;
        }
        $names = array_values(array_unique($m[1]));
        if (empty($names)) return null;

        $agents = [];
        foreach ($names as $n) $agents[] = ['name' => $n];

        $concurrency = 4;
        if (preg_match('/"concurrency"\s*:\s*(\d+)/', $raw, $c)) {
            $concurrency = (int) $c[1];
        }
        return ['agents' => $agents, 'concurrency' => $concurrency];
    }

    /**
     * Walk the input char-by-char; inside "..." string literals, replace
     * raw \n / \r / \t with their JSON-escaped forms. Outside string
     * literals leaves everything alone. Models sometimes emit unescaped
     * control chars inside string values, which json_decode rejects
     * strictly — this re-escape lets us recover.
     */
    protected static function reescapeControlCharsInJsonStrings(string $raw): string
    {
        $out = '';
        $len = strlen($raw);
        $inString = false;
        $escaped = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($escaped) {
                $out .= $c;
                $escaped = false;
                continue;
            }
            if ($c === '\\') {
                $out .= $c;
                $escaped = true;
                continue;
            }
            if ($c === '"') {
                $inString = !$inString;
                $out .= $c;
                continue;
            }
            if ($inString) {
                if ($c === "\n") { $out .= '\\n'; continue; }
                if ($c === "\r") { $out .= '\\r'; continue; }
                if ($c === "\t") { $out .= '\\t'; continue; }
                if (ord($c) < 0x20) { $out .= sprintf('\\u%04x', ord($c)); continue; }
            }
            $out .= $c;
        }
        return $out;
    }
}
