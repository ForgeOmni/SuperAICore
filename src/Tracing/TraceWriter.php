<?php

declare(strict_types=1);

namespace SuperAICore\Tracing;

/**
 * SDK-first dedup shim — see RingBuffer.php for the pattern.
 */
if (\class_exists(\SuperAgent\Tracing\TraceWriter::class)) {
    \class_alias(\SuperAgent\Tracing\TraceWriter::class, TraceWriter::class);
} else {
    require_once __DIR__ . '/Fallback/TraceWriter.php';
}
