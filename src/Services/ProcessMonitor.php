<?php

namespace SuperAICore\Services;

/**
 * Shared OS-level helpers for the process monitor: scan `ps`, tail a log
 * file safely, format stream-json (CC / SuperAgent NDJSON) into readable
 * text. All methods are side-effect-free except for reading files and
 * running read-only shell commands.
 */
class ProcessMonitor
{
    /**
     * Default keywords host apps care about. Pass a custom list to narrow or
     * extend.
     */
    const DEFAULT_KEYWORDS = ['claude', 'codex', 'superagent', 'task:execute', 'run:translate'];

    /**
     * Scan `ps aux` and return rows matching any of $keywords.
     *
     * @return array<int,array{pid:int,user:string,cpu:string,mem:string,elapsed:string,command:string,started_at:?string,full_line:string}>
     */
    public static function getSystemProcesses(array $keywords = self::DEFAULT_KEYWORDS): array
    {
        $processes = [];

        $output = [];
        @exec('ps aux 2>/dev/null', $output);

        $myPid = getmypid();

        foreach ($output as $line) {
            if (stripos($line, 'grep') !== false) continue;

            $matched = false;
            foreach ($keywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) continue;

            $parts = preg_split('/\s+/', trim($line), 11);
            if (count($parts) >= 11) {
                $pid = (int) $parts[1];
                if ($pid === $myPid) continue;

                $processes[] = [
                    'pid'        => $pid,
                    'user'       => $parts[0],
                    'cpu'        => $parts[2],
                    'mem'        => $parts[3],
                    'elapsed'    => $parts[9] ?? '-',
                    'command'    => $parts[10],
                    'started_at' => $parts[8] ?? null,
                    'full_line'  => $line,
                ];
            }
        }

        return $processes;
    }

    /**
     * Is the given OS pid still alive? Uses `posix_kill(pid, 0)` when
     * available (fastest), falls back to `ps -p` otherwise.
     */
    public static function isAlive(?int $pid): bool
    {
        if (!$pid || $pid <= 1) return false;
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        $out = [];
        @exec("ps -p {$pid} -o pid= 2>/dev/null", $out);
        return !empty($out);
    }

    /**
     * Read a tail slice of a log file and sanitize control chars.
     * Returns ['ok' => bool, 'text' => string, 'path' => ?string, 'size' => int, 'is_alive' => bool, 'error' => ?string]
     */
    public static function tailLog(?string $path, ?int $pid = null, int $maxBytes = 65536): array
    {
        if (!$path || !file_exists($path)) {
            return [
                'ok'       => false,
                'text'     => '',
                'path'     => $path,
                'size'     => 0,
                'is_alive' => self::isAlive($pid),
                'error'    => 'log file not available',
            ];
        }

        $maxBytes = max(1024, min($maxBytes, 1048576));  // 1KB..1MB
        $size = filesize($path);
        $offset = max(0, $size - $maxBytes);
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [
                'ok' => false, 'text' => '', 'path' => $path, 'size' => $size,
                'is_alive' => self::isAlive($pid), 'error' => 'cannot open file',
            ];
        }
        fseek($fh, $offset);
        $text = stream_get_contents($fh);
        fclose($fh);

        $text = self::sanitize((string) $text);
        $text = self::parseStreamJsonIfNeeded($text);
        $text = self::sanitizeUtf8($text);

        return [
            'ok' => true, 'text' => $text, 'path' => $path, 'size' => $size,
            'is_alive' => self::isAlive($pid),
        ];
    }

    /**
     * Strip control chars & ANSI escapes that `script -q` and terminal
     * emulators leave in the log file.
     */
    public static function sanitize(string $content): string
    {
        $content = preg_replace('/\^D[\x08]*/', '', $content);
        $content = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $content);
        $content = preg_replace('/\x1B\].*?\x07/', '', $content);
        $content = preg_replace('/\x1B\].*?\x1B\\\\/', '', $content);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
        return preg_replace('/\r/', '', $content);
    }

    public static function sanitizeUtf8(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) return $content;
        $normalized = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
        return is_string($normalized) ? $normalized : mb_convert_encoding($content, 'UTF-8', 'UTF-8');
    }

    /**
     * When the content looks like NDJSON stream events (CC `--output-format
     * stream-json` or SuperAgent NdjsonWriter), convert to a human-readable
     * activity log. Otherwise return content unchanged.
     */
    public static function parseStreamJsonIfNeeded(string $content): string
    {
        $firstLine = strtok($content, "\n");
        if (!$firstLine || !str_starts_with(trim($firstLine), '{') || !str_contains($firstLine, '"type"')) {
            return $content;
        }

        $readable = '';
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (!$line || !str_starts_with($line, '{')) continue;

            $json = json_decode($line, true);
            if (!$json) {
                $readable .= $line . "\n";
                continue;
            }

            $type = $json['type'] ?? '';
            switch ($type) {
                case 'system':
                    if (($json['subtype'] ?? '') === 'init') {
                        $model = $json['model'] ?? 'unknown';
                        $readable .= "🚀 Session started (model: {$model})\n";
                    }
                    break;

                case 'assistant':
                    foreach (($json['message']['content'] ?? []) as $block) {
                        $bt = $block['type'] ?? '';
                        if ($bt === 'text') {
                            $text = $block['text'] ?? '';
                            if (trim($text)) $readable .= $text . "\n";
                        } elseif ($bt === 'tool_use') {
                            $name = $block['name'] ?? 'unknown';
                            $detail = self::formatToolUseSummary($name, $block['input'] ?? []);
                            $readable .= "🔧 {$name}{$detail}\n";
                        }
                    }
                    break;

                case 'tool_result':
                    if (!empty($json['is_error'])) {
                        $err = $json['content'] ?? '';
                        if (is_string($err)) {
                            $readable .= "❌ Tool error: " . mb_substr($err, 0, 200) . "\n";
                        }
                    }
                    break;

                case 'user':
                    foreach (($json['message']['content'] ?? []) as $block) {
                        if (($block['type'] ?? '') === 'tool_result' && !empty($block['is_error'])) {
                            $err = $block['content'] ?? '';
                            if (is_string($err)) {
                                $readable .= "❌ Tool error: " . mb_substr($err, 0, 200) . "\n";
                            }
                        }
                    }
                    break;

                case 'result':
                    $subtype = $json['subtype'] ?? '';
                    $cost = $json['total_cost_usd'] ?? 0;
                    $duration = round(($json['duration_ms'] ?? 0) / 1000);
                    $icon = $subtype === 'success' ? '✅' : '❌';
                    $readable .= "\n{$icon} Completed ({$duration}s, \${$cost})\n";
                    if (!empty($json['result'])) {
                        $readable .= "---\n{$json['result']}\n";
                    }
                    break;

                // `rate_limit_event`, `turn.started`, `thread.started`, ... ignored
            }
        }

        return $readable !== '' ? $readable : $content;
    }

    /**
     * One-line summary of a tool-use block — picks the most descriptive
     * field available in the input.
     */
    public static function formatToolUseSummary(string $toolName, array $input): string
    {
        if (!$input) return '';

        static $priorityKeys = [
            'description', 'query', 'command', 'prompt', 'pattern',
            'url', 'file_path', 'path', 'name', 'skill', 'to',
        ];

        foreach ($priorityKeys as $key) {
            if (!empty($input[$key]) && is_string($input[$key])) {
                $val = $input[$key];
                if (in_array($key, ['file_path', 'path']) && str_contains($val, '/')) {
                    $val = basename($val);
                }
                return ': ' . mb_substr($val, 0, 80);
            }
        }

        foreach ($input as $val) {
            if (is_string($val) && ($trimmed = trim($val)) !== '' && mb_strlen($trimmed) <= 120) {
                return ': ' . mb_substr($trimmed, 0, 80);
            }
        }

        return '';
    }

    /**
     * Kill an OS pid (and its direct process-group children). Safe-guards:
     * refuses pid 0/1 and the current PHP pid.
     */
    public static function killPid(int $pid, string $signal = 'TERM'): bool
    {
        if ($pid <= 1 || $pid === getmypid()) return false;
        @exec("pkill -{$signal} -P " . escapeshellarg((string) $pid) . ' 2>/dev/null');
        $out = []; $exit = 0;
        @exec("kill -{$signal} " . escapeshellarg((string) $pid) . ' 2>/dev/null', $out, $exit);
        return $exit === 0;
    }

    /**
     * Kill all PIDs matching a pgrep pattern and their child processes.
     */
    public static function killCommandTreesByPattern(string $pattern): int
    {
        $pids = [];
        @exec('pgrep -f ' . escapeshellarg($pattern) . ' 2>/dev/null', $pids);
        $killed = 0;
        foreach ($pids as $pid) {
            if (self::killPid((int) $pid, 'TERM')) $killed++;
        }
        return $killed;
    }
}
