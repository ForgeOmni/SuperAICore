<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SuperAICore\Models\AiUsageLog;

/**
 * `php artisan task-results:export-jsonl` — emits pi v3-compatible session
 * JSONL files from ai_usage_logs, one file per metadata.session_id.
 *
 * The resulting files can be uploaded to a Hugging Face dataset (or any
 * archival store) and replayed by pi-compatible viewers. Output is opt-in:
 * the command refuses to run without --i-understand to acknowledge the
 * privacy implications.
 *
 * Pi session format reference: https://pi.dev/docs/latest/session-format
 *
 * Entry types emitted:
 *   - session         (header, first line)
 *   - message         (user / assistant per dispatch)
 *   - model_change    (when subsequent rows in same session use a new model)
 *   - compaction      (only when metadata.compaction_marker is set)
 */
final class TaskResultsExportJsonlCommand extends Command
{
    protected $signature = 'task-results:export-jsonl
        {--since= : ISO-8601 lower bound on created_at}
        {--until= : ISO-8601 upper bound on created_at}
        {--output= : Output directory (default: storage/exports/jsonl)}
        {--anonymize : Strip prompt + completion text bodies, keep metadata only}
        {--limit=0 : Cap on number of sessions exported (0 = unlimited)}
        {--session= : Export only this session_id}
        {--i-understand : Required acknowledgement that exported data may contain PII}';

    protected $description = 'Export ai_usage_logs as pi v3-compatible session JSONL (one file per session_id).';

    public function handle(): int
    {
        if (!$this->option('i-understand')) {
            $this->error('Refusing to export. This dataset may contain user prompts and completions.');
            $this->line('Re-run with --i-understand once you have reviewed the PII implications.');
            $this->line('Combine with --anonymize to strip prompt/completion bodies.');
            return self::FAILURE;
        }

        $outDir = (string) ($this->option('output') ?: storage_path('exports/jsonl'));
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            $this->error("Cannot create output dir: {$outDir}");
            return self::FAILURE;
        }

        $query = AiUsageLog::query()->orderBy('created_at');
        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', Carbon::parse($since));
        }
        if ($until = $this->option('until')) {
            $query->where('created_at', '<=', Carbon::parse($until));
        }
        if ($sid = $this->option('session')) {
            $query->whereJsonContains('metadata->session_id', $sid);
        }

        $bySession = [];
        $query->each(function (AiUsageLog $log) use (&$bySession) {
            $sid = $log->metadata['session_id'] ?? ('orphan:' . $log->id);
            $bySession[$sid][] = $log;
        });

        $limit = (int) $this->option('limit');
        $exported = 0;
        $anonymize = (bool) $this->option('anonymize');

        foreach ($bySession as $sessionId => $rows) {
            if ($limit > 0 && $exported >= $limit) break;

            $file = $outDir . DIRECTORY_SEPARATOR . $this->safeFilename($sessionId) . '.jsonl';
            $fh = fopen($file, 'wb');
            if (!$fh) {
                $this->warn("Skipping {$sessionId}: cannot open {$file}");
                continue;
            }

            $first = $rows[0];
            $sessionUuid = (string) ($first->metadata['session_uuid'] ?? $this->uuid());
            $cwd = (string) ($first->metadata['cwd'] ?? '');

            $this->writeLine($fh, [
                'type' => 'session',
                'version' => 3,
                'id' => $sessionUuid,
                'timestamp' => $first->created_at?->toIso8601String(),
                'cwd' => $cwd,
            ]);

            $parentId = null;
            $lastModel = null;

            foreach ($rows as $row) {
                $entryId = $this->shortId();

                if ($lastModel !== null && $lastModel !== $row->model) {
                    $modelEntry = [
                        'type' => 'model_change',
                        'id' => $this->shortId(),
                        'parentId' => $parentId,
                        'timestamp' => $row->created_at?->toIso8601String(),
                        'provider' => $row->backend,
                        'modelId' => $row->model,
                    ];
                    $this->writeLine($fh, $modelEntry);
                    $parentId = $modelEntry['id'];
                }
                $lastModel = $row->model;

                $message = $this->messageFromUsageLog($row, $anonymize);
                if ($message === null) continue;

                $entry = [
                    'type' => 'message',
                    'id' => $entryId,
                    'parentId' => $parentId,
                    'timestamp' => $row->created_at?->toIso8601String(),
                    'message' => $message,
                ];
                $this->writeLine($fh, $entry);
                $parentId = $entryId;

                if (!empty($row->metadata['compaction_marker'])) {
                    $cEntry = [
                        'type' => 'compaction',
                        'id' => $this->shortId(),
                        'parentId' => $parentId,
                        'timestamp' => $row->created_at?->toIso8601String(),
                        'summary' => (string) ($row->metadata['compaction_summary'] ?? ''),
                        'firstKeptEntryId' => (string) ($row->metadata['first_kept_entry_id'] ?? ''),
                        'tokensBefore' => (int) ($row->metadata['tokens_before'] ?? 0),
                        'fromHook' => (bool) ($row->metadata['compaction_from_hook'] ?? false),
                    ];
                    $this->writeLine($fh, $cEntry);
                    $parentId = $cEntry['id'];
                }
            }

            fclose($fh);
            $exported++;
            $this->info("Wrote {$file} (" . count($rows) . ' entries)');
        }

        $this->info("Done. Exported {$exported} session(s) to {$outDir}");
        return self::SUCCESS;
    }

    private function messageFromUsageLog(AiUsageLog $row, bool $anonymize): ?array
    {
        $meta = $row->metadata ?? [];
        $userText = (string) ($meta['prompt'] ?? '');
        $assistantText = (string) ($meta['completion'] ?? '');

        $role = ($userText !== '' && $assistantText === '') ? 'user' : 'assistant';
        $text = $role === 'user' ? $userText : $assistantText;

        if ($anonymize) {
            $text = '[redacted: ' . mb_strlen($text) . ' chars]';
        }
        if ($text === '') {
            // Fall through to a minimal stub so the entry is still parseable.
            $text = '[no text in usage_log]';
        }

        return [
            'role' => $role,
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'usage' => [
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_usd' => (float) $row->cost_usd,
                'duration_ms' => (int) ($row->duration_ms ?? 0),
            ],
            'model' => $row->model,
            'backend' => $row->backend,
        ];
    }

    private function writeLine($fh, array $obj): void
    {
        fwrite($fh, json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function shortId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function safeFilename(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $s) ?: 'session';
    }
}
