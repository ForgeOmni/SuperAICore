<?php

namespace SuperAICore\Services;

use SuperAICore\Registry\Skill;
use SuperAICore\Registry\SkillRegistry;

/**
 * BM25-based ranker over the SkillRegistry catalog.
 *
 * Inspired by OpenSpace's `skill_engine/skill_ranker.py` but pure-PHP and
 * dependency-free — no embeddings, no external API. Telemetry from
 * SkillTelemetry boosts (or penalises) candidates by recent success rate.
 *
 * Usage:
 *   $ranker = new SkillRanker(new SkillRegistry());
 *   $top = $ranker->rank("estimate effort for an outsource project", limit: 5);
 *   //  → [['skill' => Skill, 'score' => 4.21, 'breakdown' => [...]], ...]
 */
class SkillRanker
{
    /** Tokens shorter than this are dropped. */
    private const MIN_TOKEN_LEN = 2;

    /** BM25 hyperparameters (Robertson-Walker defaults). */
    private const K1 = 1.5;
    private const B  = 0.75;

    /** Telemetry boost weight: final = bm25 * (1 + alpha * (success_rate - 0.5) * applied_signal). */
    private const TELEMETRY_ALPHA = 0.4;

    /** Stopwords pruned from queries before tokenising. Kept tiny — prefer recall. */
    private const STOPWORDS = [
        'a','an','and','or','the','to','of','for','in','on','at','by','is','are','be','as',
        '一个','一种','一款','的','了','和','或','给','把','与','把','让','使',
    ];

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly bool $useTelemetry = true,
    ) {}

    /**
     * @return array<int, array{skill:Skill, score:float, breakdown:array}>
     */
    public function rank(string $query, int $limit = 10, ?array $skillNames = null): array
    {
        $skills = $this->registry->all();
        if ($skillNames !== null) {
            $skills = array_intersect_key($skills, array_flip($skillNames));
        }
        if (!$skills) return [];

        $queryTokens = $this->tokenise($query);
        if (!$queryTokens) return [];

        // Build doc tokens + corpus stats
        $docs = [];
        $docLengths = [];
        $df = []; // document frequency per term
        foreach ($skills as $name => $skill) {
            $docTokens = $this->skillDocTokens($skill);
            $docs[$name] = $docTokens;
            $docLengths[$name] = max(1, count($docTokens));
            foreach (array_unique($docTokens) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $N = count($docs);
        $avgdl = array_sum($docLengths) / max(1, $N);

        // Telemetry lookup (small map)
        $metrics = $this->useTelemetry ? SkillTelemetry::metrics() : [];

        $results = [];
        foreach ($docs as $name => $docTokens) {
            $tf = array_count_values($docTokens);
            $bm25 = 0.0;
            $matched = [];
            foreach ($queryTokens as $term) {
                if (!isset($tf[$term])) continue;
                $f = $tf[$term];
                $idf = $this->idf($N, $df[$term] ?? 0);
                $dl = $docLengths[$name];
                $score = $idf * (
                    ($f * (self::K1 + 1)) /
                    ($f + self::K1 * (1 - self::B + self::B * ($dl / $avgdl)))
                );
                $bm25 += $score;
                $matched[$term] = round($score, 4);
            }
            if ($bm25 <= 0.0) continue;

            $telBoost = 1.0;
            $tel = $metrics[strtolower($name)] ?? null;
            if ($tel) {
                $applied = (int) ($tel['applied'] ?? 0);
                $rate = (float) ($tel['completion_rate'] ?? 0.0);
                if ($applied > 0) {
                    // Confidence-weighted: rate matters more after more samples.
                    // applied_signal ∈ [0,1], saturates near applied=10.
                    $appliedSignal = min(1.0, $applied / 10.0);
                    $telBoost = 1.0 + self::TELEMETRY_ALPHA * ($rate - 0.5) * $appliedSignal;
                }
            }

            $finalScore = $bm25 * $telBoost;
            $results[] = [
                'skill'  => $skills[$name],
                'score'  => round($finalScore, 4),
                'breakdown' => [
                    'bm25'      => round($bm25, 4),
                    'tel_boost' => round($telBoost, 4),
                    'matched'   => $matched,
                    'metrics'   => $tel,
                ],
            ];
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, $limit);
    }

    private function idf(int $N, int $df): float
    {
        // BM25-Plus variant (handles df=0 gracefully).
        return log(1 + (($N - $df + 0.5) / ($df + 0.5)));
    }

    /** @return string[] */
    private function skillDocTokens(Skill $s): array
    {
        $bag = [];
        // Name carries strong intent — repeat to upweight.
        $bag[] = $s->name;
        $bag[] = $s->name;
        if ($s->description) $bag[] = $s->description;
        // First ~600 chars of body — captures the SKILL.md headline & one-liner.
        $bag[] = mb_substr($s->body ?? '', 0, 600);
        return $this->tokenise(implode(' ', $bag));
    }

    /**
     * Tokeniser: lowercase, ASCII split + CJK char-grams (length 1, since
     * Chinese skill descriptions are short). Dependency-free.
     *
     * @return string[]
     */
    private function tokenise(string $text): array
    {
        if ($text === '') return [];
        $text = mb_strtolower($text);
        // Replace separator chars with spaces (keep CJK + alnum + dashes).
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $text);
        $tokens = [];
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            if ($word === '') continue;
            // CJK: emit each char as its own token (poor-man's CJK tokenizer).
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $word)) {
                $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($chars as $c) {
                    if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $c)) {
                        $tokens[] = $c;
                    } elseif (mb_strlen($c) >= self::MIN_TOKEN_LEN) {
                        $tokens[] = $c;
                    }
                }
                continue;
            }
            if (mb_strlen($word) < self::MIN_TOKEN_LEN) continue;
            if (in_array($word, self::STOPWORDS, true)) continue;
            $tokens[] = $word;
            // Hyphenated: also include the parts.
            if (str_contains($word, '-')) {
                foreach (explode('-', $word) as $sub) {
                    if (mb_strlen($sub) >= self::MIN_TOKEN_LEN) $tokens[] = $sub;
                }
            }
        }
        return $tokens;
    }
}
