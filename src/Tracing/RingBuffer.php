<?php

declare(strict_types=1);

namespace SuperAICore\Tracing;

/**
 * SDK-first dedup shim. When forgeomni/superagent is installed (it is a
 * hard require; the degraded no-SDK path exists only for the
 * phpunit-no-superagent CI job and equivalent hosts), the SDK class is the
 * single source of truth and this name is an alias of it. Without the SDK
 * the fallback copy under Fallback/ (excluded from the classmap) loads.
 */
if (\class_exists(\SuperAgent\Tracing\RingBuffer::class)) {
    \class_alias(\SuperAgent\Tracing\RingBuffer::class, RingBuffer::class);
} else {
    require_once __DIR__ . '/Fallback/RingBuffer.php';
}
