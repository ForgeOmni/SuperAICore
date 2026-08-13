<?php

declare(strict_types=1);

namespace SuperAICore\Tracing;

final class TraceWriter
{
    public function __construct(
        private readonly string $storagePath,
        private readonly string $producer = 'superaicore',
        private readonly string $producerVersion = 'dev',
    ) {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    /** @param TraceEvent[] $events */
    public function write(
        array $events,
        string $sessionOrJobId,
        string $trigger,
        ?string $triggerReason = null,
        array $extraMetadata = [],
    ): string {
        $payload = [
            'displayTimeUnit' => 'ms',
            'metadata' => array_merge([
                'producer' => $this->producer,
                'producer_version' => $this->producerVersion,
                'session_id' => $sessionOrJobId,
                'dumped_at' => date('c'),
                'trigger' => $trigger,
                'trigger_reason' => $triggerReason,
                'event_count' => count($events),
            ], $extraMetadata),
            'traceEvents' => array_map(fn(TraceEvent $e) => $e->toArray(), $events),
        ];

        $filename = sprintf(
            'trace_%s_%s_%d_%s.json',
            $this->producer,
            $this->sanitizeId($sessionOrJobId),
            (int) (microtime(true) * 1000),
            $trigger,
        );
        $path = rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . $filename;

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );

        return $path;
    }

    private function sanitizeId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '-', $id) ?? 'unknown';
        return substr($clean, 0, 64);
    }
}
