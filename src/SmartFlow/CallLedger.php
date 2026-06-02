<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Append-only record of every agent call in a flow run ("call-ledger + 出账留底").
 * Each run writes one JSONL file under the ledger directory; one line per call:
 *
 *   {"seq":0,"label":"plan","signature":"…","backend":"claude_cli","model":"…",
 *    "layer":"submitted","input_tokens":…,"output_tokens":…,"cost_usd":…,
 *    "skip":false,"value":…}
 *
 * It also powers resume: when a run is started with the entries of a *prior* run,
 * {@see matchPrior()} returns the cached entry at a given position iff its
 * signature still matches — the basis for the longest-unchanged-prefix replay.
 */
final class CallLedger
{
    /** @var list<array<string, mixed>> entries written during THIS run */
    private array $entries = [];

    /** @var list<array<string, mixed>> ordered entries from the PRIOR run (resume) */
    private array $prior;

    private int $seq = 0;

    /**
     * @param list<array<string, mixed>> $prior
     */
    public function __construct(
        private readonly string $runId,
        private readonly ?string $path = null,
        array $prior = [],
    ) {
        $this->prior = array_values($prior);
        if ($this->path !== null) {
            @mkdir(dirname($this->path), 0775, true);
        }
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Append a recorded call. Returns the stored entry (with assigned seq).
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function append(array $entry): array
    {
        $entry['seq'] = $this->seq++;
        $this->entries[] = $entry;
        if ($this->path !== null) {
            @file_put_contents(
                $this->path,
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        return $entry;
    }

    /**
     * Return the prior-run entry at $seq iff it carries the given signature —
     * i.e. that call is unchanged and its cached value can be reused.
     *
     * @return array<string, mixed>|null
     */
    public function matchPrior(int $seq, string $signature): ?array
    {
        $entry = $this->prior[$seq] ?? null;
        if ($entry !== null && ($entry['signature'] ?? null) === $signature) {
            return $entry;
        }
        return null;
    }

    public function hasPrior(): bool
    {
        return $this->prior !== [];
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $cost = 0.0;
        $inTok = 0;
        $outTok = 0;
        $calls = 0;
        $cached = 0;
        $skips = 0;
        $gates = 0;
        $layers = [];
        foreach ($this->entries as $e) {
            $cost += (float) ($e['cost_usd'] ?? 0);
            $inTok += (int) ($e['input_tokens'] ?? 0);
            $outTok += (int) ($e['output_tokens'] ?? 0);
            // Gates and other non-agent markers don't count as model calls.
            if (($e['kind'] ?? 'agent') === 'gate') {
                $gates++;
                continue;
            }
            $calls++;
            $cached += !empty($e['cached']) ? 1 : 0;
            $skips += !empty($e['skip']) ? 1 : 0;
            $layer = (string) ($e['layer'] ?? 'text');
            $layers[$layer] = ($layers[$layer] ?? 0) + 1;
        }

        return [
            'run_id' => $this->runId,
            'calls' => $calls,
            'cached_calls' => $cached,
            'skips' => $skips,
            'gates' => $gates,
            'cost_usd' => round($cost, 6),
            'input_tokens' => $inTok,
            'output_tokens' => $outTok,
            'layers' => $layers,
            'path' => $this->path,
        ];
    }

    /**
     * Load a prior run's entries from its JSONL file (for resume). Returns an
     * empty list if the file is missing or unreadable.
     *
     * @return list<array<string, mixed>>
     */
    public static function readEntries(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        // Order by seq to be safe against partial/interleaved writes.
        usort($out, static fn ($a, $b) => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));
        return $out;
    }

    /**
     * Resolve the directory where ledger files live. Honors an explicit override,
     * then config, then the shared `~/.superaicore` storage convention.
     */
    public static function resolveDir(): string
    {
        $env = getenv('SUPERAICORE_FLOW_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/\\');
        }
        $cfg = Cfg::get('super-ai-core.smartflow.ledger_dir');
        if (is_string($cfg) && $cfg !== '') {
            return rtrim($cfg, '/\\');
        }
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME')
            ?: ($_SERVER['USERPROFILE'] ?? getenv('USERPROFILE') ?: sys_get_temp_dir());
        return rtrim($home, '/\\') . '/.superaicore/flows';
    }

    public static function newRunId(string $flowName = 'flow'): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $flowName) ?: 'flow';
        return strtolower(trim($slug, '-')) . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
}
