<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Thrown when an agent call would breach the flow's USD or token ceiling.
 * Caught by {@see FlowEngine::run()} and turned into a failed {@see FlowResult}.
 */
final class BudgetExceededException extends \RuntimeException
{
}
