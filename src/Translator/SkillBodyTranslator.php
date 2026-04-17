<?php

namespace SuperAICore\Translator;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Registry\Skill;

/**
 * Rewrites Claude-Code tool references in a skill body to the target
 * backend's equivalents (per `BackendCapabilities::toolNameMap()`).
 *
 * Rewrite is intentionally conservative — a bare capitalised word like
 * `Read` is ambiguous (prose "Read the docs" vs. tool invocation). We
 * only rewrite when the shape makes the intent unambiguous:
 *
 *   - `` `Read` `` — backtick-wrapped identifier
 *   - `Read(...)` — function-call shape
 *   - "the `Read` tool", "the Read tool"
 *   - "use/using/call/calling/invoke/invoking Read"
 *
 * Prose uses of the word are left alone; the backend preamble (see
 * stage 2) carries the translation hint for the model to interpret
 * context-dependent references itself. This avoids mangling lines like
 * "Read the config carefully and Write a one-paragraph summary."
 *
 * Tools used anywhere in the body (loose detection) that have no
 * mapping on the target are reported as `untranslated` so callers can
 * downgrade probe verdicts. That check is intentionally more permissive
 * than the rewrite — over-flagging a compatibility gap is safer than
 * missing one.
 */
final class SkillBodyTranslator
{
    /** Canonical Claude tool surface we know about. */
    public const CANONICAL_TOOLS = [
        'Agent', 'Task', 'TodoWrite',
        'WebSearch', 'WebFetch',
        'Read', 'Write', 'Edit',
        'Glob', 'Grep', 'Bash',
        'NotebookEdit',
    ];

    public function __construct(private readonly BackendCapabilities $target) {}

    /**
     * @return array{body:string, translated:array<string,string>, untranslated:string[]}
     */
    public function translate(Skill $skill): array
    {
        $map = $this->target->toolNameMap();
        $body = $skill->body;
        $translated = [];
        $untranslated = [];

        // Stage 1 — tool-name rewrite. Empty map means the backend uses
        // the canonical Claude tool vocabulary natively (claude/codex/
        // superagent); no rewrite, no gap report.
        if ($map) {
            foreach ($map as $from => $to) {
                [$body, $n] = self::rewriteOne($body, $from, $to);
                if ($n > 0) {
                    $translated[$from] = $to;
                }
            }
            foreach (self::CANONICAL_TOOLS as $tool) {
                if (isset($map[$tool])) {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($tool, '/') . '\b/', $skill->body)) {
                    $untranslated[] = $tool;
                }
            }
        }

        // Stage 2 — backend-specific prompt transform. Gemini/Codex inject
        // a preamble that steers the model onto the backend's own tool
        // surface and sub-agent protocol; Claude/SuperAgent are identity.
        // Idempotent — each preamble carries a marker and is skipped on
        // repeat transforms.
        $body = $this->target->transformPrompt($body);

        return [
            'body' => $body,
            'translated' => $translated,
            'untranslated' => $untranslated,
        ];
    }

    /**
     * @return array{0:string, 1:int}  [new body, total match count across all shapes]
     */
    private static function rewriteOne(string $body, string $from, string $to): array
    {
        $q = preg_quote($from, '/');
        $total = 0;
        $patterns = [
            '/`' . $q . '`/'                                                 => '`' . $to . '`',
            '/\b' . $q . '\(/'                                               => $to . '(',
            '/\bthe\s+`?' . $q . '`?\s+tool\b/i'                             => 'the ' . $to . ' tool',
            '/\b(use|using|call|calling|invoke|invoking)\s+`?' . $q . '`?\b/i' => '$1 ' . $to,
        ];
        foreach ($patterns as $pat => $rep) {
            $count = 0;
            $body = preg_replace($pat, $rep, $body, -1, $count) ?? $body;
            $total += $count;
        }
        return [$body, $total];
    }
}
