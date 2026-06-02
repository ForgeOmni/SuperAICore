<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Thrown by {@see Flow::gate()} when a `required` acceptance checkpoint fails
 * and no fallback/relay supplied a substitute value.
 */
final class GateFailedException extends \RuntimeException
{
}
