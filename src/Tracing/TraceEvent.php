<?php

declare(strict_types=1);

namespace SuperAICore\Tracing;

/**
 * SDK-first dedup shim — see RingBuffer.php for the pattern. Wire contract
 * unchanged: see SuperTeam's .claude/refs/ref-trace-format.md.
 */
if (\class_exists(\SuperAgent\Tracing\TraceEvent::class)) {
    \class_alias(\SuperAgent\Tracing\TraceEvent::class, TraceEvent::class);
} else {
    require_once __DIR__ . '/Fallback/TraceEvent.php';
}
