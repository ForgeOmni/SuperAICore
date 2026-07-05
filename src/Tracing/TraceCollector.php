<?php

declare(strict_types=1);

namespace SuperAICore\Tracing;

/**
 * Dispatcher-scoped trace collector.
 *
 * Wired by SuperAICoreServiceProvider; Dispatcher emits trace events on
 * every llm/tool call and dump()s on triggers (QuotaExceededException,
 * auto-rotate, soft timeout, manual dispatcher:dump-trace).
 *
 * See SuperTeam .claude/refs/ref-trace-format.md for the wire contract.
 */
final class TraceCollector
{
    private static ?self $instance = null;

    private RingBuffer $ring;
    private bool $enabled;
    private string $sessionId;
    private ?TraceWriter $writer = null;

    public function __construct(
        ?RingBuffer $ring = null,
        bool $enabled = true,
        ?string $sessionId = null,
        ?TraceWriter $writer = null,
    ) {
        $this->ring = $ring ?? new RingBuffer(1024);
        $this->enabled = $enabled;
        $this->sessionId = $sessionId ?? bin2hex(random_bytes(8));
        $this->writer = $writer;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $storagePath = self::resolveStoragePath();
            $enabled = self::resolveEnabledFlag();
            $capacity = self::resolveCapacity();

            self::$instance = new self(
                ring: new RingBuffer($capacity),
                enabled: $enabled,
                sessionId: null,
                writer: new TraceWriter($storagePath, 'superaicore'),
            );
        }

        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    private static function resolveStoragePath(): string
    {
        // ConfigValue guards against a dev checkout where the config()
        // helper is autoloaded but no container is booted (standalone
        // bin/superaicore) — calling it then throws instead of returning null.
        $configured = \SuperAICore\Support\ConfigValue::get('super-ai-core.tracing.storage_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (function_exists('storage_path')) {
            try {
                return storage_path('app/superaicore/traces');
            } catch (\Throwable) {
                // no container bound — fall through to the temp default
            }
        }

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superaicore-traces';
    }

    private static function resolveEnabledFlag(): bool
    {
        $configured = \SuperAICore\Support\ConfigValue::get('super-ai-core.tracing.enabled');
        if (is_bool($configured)) {
            return $configured;
        }
        $envFlag = getenv('AI_CORE_TRACE_ENABLED');

        return $envFlag === false ? true : ($envFlag !== 'false' && $envFlag !== '0');
    }

    private static function resolveCapacity(): int
    {
        $configured = \SuperAICore\Support\ConfigValue::get('super-ai-core.tracing.ring_size');
        if (is_int($configured) && $configured > 0) {
            return $configured;
        }
        $env = getenv('AI_CORE_TRACE_RING_SIZE');

        return is_string($env) && ctype_digit($env) ? (int) $env : 1024;
    }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; }

    public function setSessionId(string $sessionId): void { $this->sessionId = $sessionId; }
    public function getSessionId(): string { return $this->sessionId; }

    public function emitDuration(
        string $name,
        string $category,
        string $tid,
        int $startMicros,
        int $durationMicros,
        array $args = [],
        string $pid = 'superaicore',
        ?string $color = null,
    ): void {
        if (!$this->enabled) return;
        $this->ring->push(TraceEvent::duration(
            $name, $category, $pid, $tid, $startMicros, $durationMicros, $args, $color,
        ));
    }

    public function emitInstant(
        string $name,
        string $category,
        string $tid,
        array $args = [],
        string $pid = 'superaicore',
        ?string $color = null,
    ): void {
        if (!$this->enabled) return;
        $this->ring->push(TraceEvent::instant(
            name: $name, category: $category, pid: $pid, tid: $tid,
            args: $args, color: $color,
        ));
    }

    public function emitCounter(
        string $name,
        string $category,
        string $tid,
        array $values,
        string $pid = 'superaicore',
    ): void {
        if (!$this->enabled) return;
        $this->ring->push(TraceEvent::counter($name, $category, $pid, $tid, $values));
    }

    public function span(
        string $name,
        string $category,
        string $tid,
        array $initialArgs = [],
        string $pid = 'superaicore',
    ): callable {
        $start = (int) (microtime(true) * 1_000_000);

        return function (array $extraArgs = []) use ($name, $category, $tid, $start, $initialArgs, $pid): void {
            $now = (int) (microtime(true) * 1_000_000);
            $this->emitDuration(
                name: $name,
                category: $category,
                tid: $tid,
                startMicros: $start,
                durationMicros: $now - $start,
                args: array_merge($initialArgs, $extraArgs),
                pid: $pid,
            );
        };
    }

    public function dump(string $trigger, ?string $reason = null, array $extraMetadata = []): ?string
    {
        if (!$this->enabled || $this->writer === null) {
            return null;
        }
        $events = $this->ring->snapshot();
        if (empty($events)) {
            return null;
        }

        return $this->writer->write(
            events: $events,
            sessionOrJobId: $this->sessionId,
            trigger: $trigger,
            triggerReason: $reason,
            extraMetadata: $extraMetadata,
        );
    }

    public function getRing(): RingBuffer { return $this->ring; }
    public function setWriter(TraceWriter $writer): void { $this->writer = $writer; }
}
