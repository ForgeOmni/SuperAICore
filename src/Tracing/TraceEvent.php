<?php

declare(strict_types=1);

namespace SuperAICore\Tracing;

/**
 * Immutable Chrome Trace Event record.
 *
 * Cross-repo contract: see SuperTeam's .claude/refs/ref-trace-format.md.
 * Wire-compatible with SuperAgent\Tracing\TraceEvent and the bundled
 * trace-viewer.html / Perfetto / chrome://tracing / magic-trace.org.
 */
final class TraceEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly string $phase,
        public readonly string $pid,
        public readonly string $tid,
        public readonly int $tsMicros,
        public readonly ?int $durationMicros,
        public readonly array $args = [],
        public readonly ?string $scope = null,
        public readonly ?string $color = null,
    ) {}

    public static function duration(
        string $name,
        string $category,
        string $pid,
        string $tid,
        int $startMicros,
        int $durationMicros,
        array $args = [],
        ?string $color = null,
    ): self {
        return new self($name, $category, 'X', $pid, $tid, $startMicros, $durationMicros, $args, null, $color);
    }

    public static function instant(
        string $name,
        string $category,
        string $pid,
        string $tid,
        ?int $tsMicros = null,
        array $args = [],
        string $scope = 'g',
        ?string $color = null,
    ): self {
        return new self(
            $name, $category, 'i', $pid, $tid,
            $tsMicros ?? (int) (microtime(true) * 1_000_000),
            null, $args, $scope, $color,
        );
    }

    public static function counter(
        string $name,
        string $category,
        string $pid,
        string $tid,
        array $values,
        ?int $tsMicros = null,
    ): self {
        return new self(
            $name, $category, 'C', $pid, $tid,
            $tsMicros ?? (int) (microtime(true) * 1_000_000),
            null, $values, null, null,
        );
    }

    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'cat' => $this->category,
            'ph' => $this->phase,
            'pid' => $this->pid,
            'tid' => $this->tid,
            'ts' => $this->tsMicros,
        ];
        if ($this->durationMicros !== null) $out['dur'] = $this->durationMicros;
        if (!empty($this->args)) $out['args'] = $this->args;
        if ($this->scope !== null) $out['s'] = $this->scope;
        if ($this->color !== null) $out['cname'] = $this->color;

        return $out;
    }
}
