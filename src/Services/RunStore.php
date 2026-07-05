<?php

namespace SuperAICore\Services;

/**
 * Structured run archive for `superaicore send` / `resume` — ai-dispatch
 * parity (`~/.ai-dispatch/runs/`).
 *
 * One JSON file per dispatch, named `<id>.json`, where the id embeds a
 * sortable UTC timestamp. `runs list` walks the directory newest-first;
 * `resume --session-id` uses `findBySession()` to learn which backend
 * owns a session without the caller restating it.
 *
 * Storage resolution:
 *   1. `super-ai-core.dispatch.runs_path` config (Laravel host)
 *   2. `AI_CORE_RUNS_PATH` env
 *   3. `~/.superaicore/runs` (standalone CLI default)
 *
 * Deliberately filesystem-only — the usage DB already has the analytic
 * copy; this store exists so a headless CLI (or another agent driving us
 * through the dispatch SKILL) can audit results with zero DB access.
 */
class RunStore
{
    public function __construct(protected ?string $path = null) {}

    public function path(): string
    {
        if ($this->path !== null) return $this->path;

        $configured = \SuperAICore\Support\ConfigValue::get('super-ai-core.dispatch.runs_path')
            ?: (getenv('AI_CORE_RUNS_PATH') ?: null);

        return $this->path = $configured
            ?? rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/') . '/.superaicore/runs';
    }

    /**
     * Persist one run record; returns the run id. Never throws — an
     * unwritable disk must not fail the dispatch whose result it archives.
     *
     * @param array<string,mixed> $run
     */
    public function record(array $run): ?string
    {
        $id = $run['run_id'] ?? (gmdate('Ymd\THis\Z') . '-' . substr(bin2hex(random_bytes(4)), 0, 6));
        $run['run_id'] = $id;
        $run['recorded_at'] ??= gmdate('c');

        try {
            $dir = $this->path();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
            $json = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) return null;
            return @file_put_contents($dir . '/' . $id . '.json', $json . "\n") === false ? null : $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $file = $this->path() . '/' . basename($id, '.json') . '.json';
        if (!is_file($file)) return null;
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Newest-first run summaries.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $limit = 20): array
    {
        $out = [];
        foreach ($this->files() as $file) {
            if (count($out) >= $limit) break;
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (!is_array($decoded)) continue;
            $out[] = [
                'run_id' => $decoded['run_id'] ?? basename($file, '.json'),
                'recorded_at' => $decoded['recorded_at'] ?? null,
                'status' => $decoded['status'] ?? null,
                'requested_target' => $decoded['requested_target'] ?? null,
                'backend_used' => $decoded['backend_used'] ?? null,
                'model_used' => $decoded['model_used'] ?? null,
                'session_id' => $decoded['session_id'] ?? null,
                'task_name' => $decoded['task_name'] ?? null,
                'degraded' => $decoded['degraded'] ?? false,
                'cost_usd' => $decoded['cost_usd'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Most recent run that produced the given session id — the resume
     * path uses this to re-route a follow-up to the owning backend.
     *
     * @return array<string,mixed>|null
     */
    public function findBySession(string $sessionId): ?array
    {
        if (trim($sessionId) === '') return null;
        foreach ($this->files() as $file) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && ($decoded['session_id'] ?? null) === $sessionId) {
                return $decoded;
            }
        }
        return null;
    }

    /** @return list<string> absolute paths, newest first (id embeds UTC timestamp) */
    protected function files(): array
    {
        $dir = $this->path();
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.json') ?: [];
        rsort($files, SORT_STRING);
        return $files;
    }
}
