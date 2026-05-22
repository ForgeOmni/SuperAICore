<?php

declare(strict_types=1);

namespace SuperAICore\Contracts;

use Generator;

/**
 * 9Router-borrowed real-streaming variant of {@see Backend}.
 *
 * Sibling of `Backend::generate()` (one-shot batch) and `StreamingBackend::stream()`
 * (CLI subprocess tee-to-log). This contract is for HTTP API backends that
 * can yield text chunks as the model produces them — what an OpenAI-compatible
 * SSE proxy needs to push live token deltas to a client.
 *
 * The generator yields one of:
 *
 *   ['type' => 'text', 'delta' => '<chunk>']        text continuation
 *   ['type' => 'usage', 'input_tokens' => N,
 *                       'output_tokens' => M]       usage update (may fire
 *                                                    once or many times; the
 *                                                    last value wins)
 *   ['type' => 'stop',  'reason' => 'end_turn']     stream terminator
 *
 * Backends MAY yield additional types (e.g. 'thinking', 'tool_use') but
 * consumers (the OpenAI-compat proxy) only care about text + usage + stop.
 * Unknown types are ignored by the proxy.
 *
 * Errors:
 *   - Network / API failures: throw — caller handles
 *   - Empty stream: yield 'stop' without any 'text' (legal)
 */
interface StreamableTextBackend extends Backend
{
    /**
     * Yield text chunks as the model generates. Same option shape as
     * `generate()` — provider_config + prompt/messages + model/max_tokens.
     *
     * @param  array<string,mixed> $options
     * @return Generator<int, array<string,mixed>, mixed, void>
     */
    public function generateStream(array $options): Generator;
}
