<?php

declare(strict_types=1);

namespace SuperAICore\Tracing;

final class RingBuffer
{
    /** @var array<int, TraceEvent> */
    private array $events = [];

    private int $head = 0;
    private int $count = 0;

    public function __construct(public readonly int $capacity = 1024)
    {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('Ring capacity must be ≥ 1');
        }
    }

    public function push(TraceEvent $event): void
    {
        $this->events[$this->head] = $event;
        $this->head = ($this->head + 1) % $this->capacity;
        if ($this->count < $this->capacity) $this->count++;
    }

    /** @return TraceEvent[] oldest-first */
    public function snapshot(): array
    {
        if ($this->count === 0) return [];
        if ($this->count < $this->capacity) {
            return array_slice($this->events, 0, $this->count);
        }
        $out = [];
        for ($i = 0; $i < $this->capacity; $i++) {
            $idx = ($this->head + $i) % $this->capacity;
            if (isset($this->events[$idx])) $out[] = $this->events[$idx];
        }
        return $out;
    }

    public function clear(): void
    {
        $this->events = [];
        $this->head = 0;
        $this->count = 0;
    }

    public function count(): int { return $this->count; }
    public function isFull(): bool { return $this->count >= $this->capacity; }
}
