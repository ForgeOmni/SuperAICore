<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs a batch of {@see AgentCall}s as concurrent OS processes — the engine
 * behind SmartFlow's "true parallel" `parallel()` / `pipeline()`. Each call is
 * shipped to a `bin/flow-agent-runner.php` worker; up to `concurrency` run at
 * once. Results are returned in submission order regardless of completion order;
 * a crashed/timed-out worker yields a failed {@see AgentResult} (→ Skip/null for
 * the flow author).
 *
 * Built on `proc_open` + `stream_select`, with a Windows polling fallback. When
 * `proc_open` is unavailable it degrades to a deterministic in-process
 * sequential run so flows still work everywhere. This is what lets one flow run
 * its reviewers on `codex_cli` and `gemini_cli` genuinely at the same time.
 */
final class ProcessPool
{
    private LoggerInterface $logger;
    private string $worker;

    public function __construct(
        private int $concurrency = 4,
        private ?string $basePath = null,
        private ?string $defaultBackend = null,
        private ?string $defaultModel = null,
        private bool $fake = false,
        private int $timeoutSeconds = 600,
        ?string $workerScript = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->concurrency = max(1, $concurrency);
        $this->worker = $workerScript ?? (dirname(__DIR__, 2) . '/bin/flow-agent-runner.php');
        $this->logger = $logger ?? new NullLogger();
    }

    public function isAvailable(): bool
    {
        return function_exists('proc_open')
            && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)
            && is_file($this->worker)
            && PHP_BINARY !== '';
    }

    /**
     * @param list<AgentCall> $calls
     * @return list<AgentResult>
     */
    public function runBatch(array $calls): array
    {
        if ($calls === []) {
            return [];
        }
        if (!$this->isAvailable()) {
            return $this->sequentialFallback($calls);
        }

        $n = count($calls);
        $results = array_fill(0, $n, null);
        $procs = [];            // index => ['proc'=>, 'pipes'=>, 'out'=>'', 'err'=>'', 'start'=>float]
        $next = 0;              // next call index to launch
        $isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0 || DIRECTORY_SEPARATOR === '\\';

        $launch = function (int $i) use (&$procs, $calls): void {
            $payload = json_encode([
                'call' => $calls[$i]->toArray(),
                'fake' => $this->fake,
                'default_backend' => $this->defaultBackend,
                'default_model' => $this->defaultModel,
                'base_path' => $this->basePath,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $cmd = $this->command();
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $pipes = [];
            $proc = @proc_open($cmd, $descriptors, $pipes, $this->basePath ?: null);
            if (!is_resource($proc)) {
                $this->logger->warning('flow ProcessPool: proc_open failed', ['index' => $i]);
                $procs[$i] = ['proc' => null, 'pipes' => [], 'out' => '', 'err' => 'proc_open failed', 'start' => microtime(true)];
                return;
            }
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $procs[$i] = ['proc' => $proc, 'pipes' => $pipes, 'out' => '', 'err' => '', 'start' => microtime(true)];
        };

        // Prime the pool.
        while ($next < $n && count($procs) < $this->concurrency) {
            $launch($next++);
        }

        while ($procs !== []) {
            $readStreams = [];
            foreach ($procs as $i => $p) {
                if ($p['proc'] === null) {
                    // launch-failed slot — finalize immediately
                    $results[$i] = $this->failed($calls[$i], $p['err'] ?: 'launch failed');
                    unset($procs[$i]);
                    if ($next < $n) {
                        $launch($next++);
                    }
                    continue;
                }
                if (is_resource($p['pipes'][1])) {
                    $readStreams[] = $p['pipes'][1];
                }
                if (is_resource($p['pipes'][2])) {
                    $readStreams[] = $p['pipes'][2];
                }
            }

            if ($procs === []) {
                break;
            }

            if (!$isWindows && $readStreams !== []) {
                $write = null;
                $except = null;
                @stream_select($readStreams, $write, $except, 0, 200_000);
            } elseif ($isWindows) {
                usleep(20_000);
            }

            foreach ($procs as $i => &$p) {
                if ($p['proc'] === null) {
                    continue;
                }
                $p['out'] .= $this->drain($p['pipes'][1]);
                $p['err'] .= $this->drain($p['pipes'][2]);

                $status = proc_get_status($p['proc']);
                $timedOut = (microtime(true) - $p['start']) > $this->timeoutSeconds;

                if ($timedOut && $status['running']) {
                    @proc_terminate($p['proc']);
                    $results[$i] = $this->failed($calls[$i], 'worker timed out');
                    $this->closeProc($p);
                    unset($procs[$i]);
                    if ($next < $n) {
                        $launch($next++);
                    }
                    continue;
                }

                if (!$status['running']) {
                    // Drain any tail then finalize.
                    $p['out'] .= $this->drain($p['pipes'][1]);
                    $p['err'] .= $this->drain($p['pipes'][2]);
                    $results[$i] = $this->parse($calls[$i], $p['out'], $p['err']);
                    $this->closeProc($p);
                    unset($procs[$i]);
                    if ($next < $n) {
                        $launch($next++);
                    }
                }
            }
            unset($p);
        }

        // Any straggler nulls (shouldn't happen) become failures.
        foreach ($results as $i => $r) {
            if ($r === null) {
                $results[$i] = $this->failed($calls[$i], 'no result');
            }
        }

        return array_values($results);
    }

    private function command(): string
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        return $this->arg($php) . ' ' . $this->arg($this->worker);
    }

    private function arg(string $s): string
    {
        return '"' . str_replace('"', '\\"', $s) . '"';
    }

    private function drain($pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }
        $buf = '';
        while (($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
            $buf .= $chunk;
        }
        return $buf;
    }

    private function closeProc(array &$p): void
    {
        foreach ($p['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($p['proc'])) {
            @proc_close($p['proc']);
        }
        $p['proc'] = null;
    }

    private function parse(AgentCall $call, string $stdout, string $stderr): AgentResult
    {
        // The worker prints exactly one JSON line on stdout; tolerate trailing noise.
        $line = trim($stdout);
        if ($line !== '') {
            // Take the last non-empty line in case bootstrap warnings leaked.
            $lines = preg_split('/\r?\n/', $line) ?: [$line];
            for ($k = count($lines) - 1; $k >= 0; $k--) {
                $decoded = json_decode(trim($lines[$k]), true);
                if (is_array($decoded)) {
                    return AgentResult::fromWorker($decoded);
                }
            }
        }
        return $this->failed($call, 'unparseable worker output' . ($stderr !== '' ? ': ' . trim($stderr) : ''));
    }

    private function failed(AgentCall $call, string $error): AgentResult
    {
        return new AgentResult(
            value: $call->schema !== null ? Skip::instance() : '',
            text: '',
            layer: 'none',
            backend: '',
            model: '',
            valid: false,
            error: $error,
            fake: $this->fake,
        );
    }

    /**
     * @param list<AgentCall> $calls
     * @return list<AgentResult>
     */
    private function sequentialFallback(array $calls): array
    {
        $runner = new BackendAgentRunner(
            personas: PersonaRegistry::load(),
            fake: $this->fake,
            defaultBackend: $this->defaultBackend,
            defaultModel: $this->defaultModel,
            logger: $this->logger,
        );
        $out = [];
        foreach ($calls as $call) {
            $out[] = $runner->run($call);
        }
        return $out;
    }
}
