<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use SuperAICore\Models\AiRoutingCombo;
use SuperAICore\Services\Dispatcher;

/**
 * 9Router-borrowed OpenAI-compatible proxy.
 *
 * Surfaces SuperAICore's dispatcher behind the standard OpenAI Chat
 * Completions API surface so any OpenAI-API-compatible client — Cursor,
 * Cline, Roo Code, Kiro, continue.dev, the OpenAI SDK itself — can
 * point at SuperAICore as a drop-in backend.
 *
 *   GET  /super-ai-core/v1/models                  – list available models
 *   POST /super-ai-core/v1/chat/completions        – run a chat completion
 *
 * Request shape mirrors OpenAI: { model, messages[], stream, ... }.
 * `model` can be either:
 *   - a literal model id (e.g. "gpt-5.1", "claude-opus-4-7")
 *   - a SuperAICore routing combo name (e.g. "premium-coding")
 *     — when matched, the combo's first entry is used as primary and
 *       the rest become a fallback chain via Dispatcher options.
 *
 * Disable globally with `super-ai-core.openai_compat.enabled = false`.
 * Auth: the controller honours the same middleware as the rest of
 * `/super-ai-core/*`. For headless OpenAI clients you typically want
 * `super-ai-core.route.middleware = ['api']` + a bearer-token guard;
 * the package ships with the default `['web', 'auth']` so review your
 * router config before exposing publicly.
 */
final class OpenAiCompatibleController extends Controller
{
    public function __construct(private Dispatcher $dispatcher) {}

    public function listModels(): JsonResponse
    {
        $models = [];
        // Catalog from configured providers + known combos
        if (function_exists('config')) {
            foreach ((array) config('super-ai-core.models.known', []) as $m) {
                if (!is_string($m)) continue;
                $models[] = $this->modelObj($m, 'model');
            }
        }
        if (class_exists(AiRoutingCombo::class)) {
            try {
                AiRoutingCombo::query()->active()->orderBy('name')->each(function (AiRoutingCombo $c) use (&$models) {
                    $models[] = $this->modelObj($c->name, 'combo');
                });
            } catch (\Throwable) {
                // DB not migrated / unavailable — degrade silently
            }
        }
        return response()->json(['object' => 'list', 'data' => $models]);
    }

    public function chatCompletions(Request $request)
    {
        $data = $request->validate([
            'model'    => ['required', 'string', 'max:120'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role'    => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['required'],
            'stream'      => ['nullable', 'boolean'],
            'temperature' => ['nullable', 'numeric'],
            'max_tokens'  => ['nullable', 'integer', 'min:1'],
            'tools'       => ['nullable', 'array'],
        ]);

        $modelOrCombo = (string) $data['model'];
        $stream = (bool) ($data['stream'] ?? false);

        // Translate OpenAI messages → a single prompt + system_prompt for
        // the backend. Keeping the translation lossy-simple is fine here:
        // SuperAICore's backends are message-naive (CLI processes that
        // take a single text prompt). Round-trip fidelity matters for
        // OpenAI Responses API clients but not for the bulk of code
        // assistants on Chat Completions.
        [$prompt, $systemPrompt] = $this->messagesToPrompt($data['messages']);

        $options = [
            'prompt'        => $prompt,
            'system_prompt' => $systemPrompt,
            'task_type'     => 'openai-compat',
            'capability'    => 'reasoning-quick',
            'metadata'      => [
                'openai_compat' => true,
                'requested_model' => $modelOrCombo,
            ],
        ];

        if (isset($data['max_tokens'])) {
            $options['max_tokens'] = (int) $data['max_tokens'];
        }
        if (isset($data['temperature'])) {
            $options['temperature'] = (float) $data['temperature'];
        }

        // Combo resolution
        if (class_exists(AiRoutingCombo::class)) {
            try {
                $entries = AiRoutingCombo::resolveEntries($modelOrCombo);
                if ($entries !== []) {
                    $options['combo'] = $modelOrCombo;
                    $options['combo_entries'] = $entries;
                    $primary = $entries[0];
                    $options['backend'] = $primary['provider'] ?? null;
                    if (!empty($primary['model'])) {
                        $options['model'] = $primary['model'];
                    }
                }
            } catch (\Throwable) {
                // Fall through to literal model
            }
        }
        if (!isset($options['model'])) {
            $options['model'] = $modelOrCombo;
        }

        if ($stream) {
            return $this->streamResponse($options, $modelOrCombo);
        }

        $result = $this->dispatcher->dispatch($options);
        if ($result === null) {
            return response()->json([
                'error' => [
                    'message' => 'SuperAICore Dispatcher returned no result (no backend resolved or all backends failed).',
                    'type'    => 'server_error',
                ],
            ], 502);
        }

        return response()->json($this->toOpenAiResponse($result, $modelOrCombo, $stream));
    }

    /**
     * @param array<int, array{role:string, content:mixed}> $messages
     * @return array{0:string, 1:?string}
     */
    private function messagesToPrompt(array $messages): array
    {
        $systemParts = [];
        $body = [];
        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = $this->extractText($msg['content']);
            if ($role === 'system') {
                $systemParts[] = $content;
            } elseif ($role === 'user') {
                $body[] = 'User: ' . $content;
            } elseif ($role === 'assistant') {
                $body[] = 'Assistant: ' . $content;
            } elseif ($role === 'tool') {
                $body[] = 'Tool result: ' . $content;
            }
        }
        $systemPrompt = $systemParts === [] ? null : implode("\n\n", $systemParts);
        return [implode("\n\n", $body), $systemPrompt];
    }

    private function extractText(mixed $content): string
    {
        if (is_string($content)) return $content;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_string($block)) { $parts[] = $block; continue; }
                if (is_array($block) && isset($block['text'])) {
                    $parts[] = (string) $block['text'];
                }
            }
            return implode("\n", $parts);
        }
        return (string) $content;
    }

    private function toOpenAiResponse(array $result, string $modelLabel, bool $stream): array
    {
        $id = 'sap-' . substr(bin2hex(random_bytes(8)), 0, 24);
        $text = (string) ($result['text'] ?? '');
        $usage = $result['usage'] ?? [];

        return [
            'id'      => $id,
            'object'  => $stream ? 'chat.completion.chunk' : 'chat.completion',
            'created' => time(),
            'model'   => $result['model'] ?? $modelLabel,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role'    => 'assistant',
                    'content' => $text,
                ],
                'finish_reason' => $result['stop_reason'] ?? 'stop',
            ]],
            'usage' => [
                'prompt_tokens'     => (int) ($usage['input_tokens']  ?? 0),
                'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'total_tokens'      => (int) (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)),
            ],
            'x_superaicore' => [
                'backend'     => $result['backend']  ?? null,
                'cost_usd'    => (float) ($result['cost_usd'] ?? 0),
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            ],
        ];
    }

    /**
     * Streaming. Prefers a real provider stream when the resolved backend
     * implements StreamableTextBackend (Anthropic API today, OpenAI / Gemini
     * to follow). Falls back to synthetic chunking (batch the full result
     * then split into pieces) for batch-only backends like CLI subprocess
     * adapters.
     */
    private function streamResponse(array $options, string $modelLabel): StreamedResponse
    {
        return new StreamedResponse(function () use ($options, $modelLabel) {
            $id = 'sap-' . substr(bin2hex(random_bytes(8)), 0, 24);
            $modelOut = $options['model'] ?? $modelLabel;

            // role chunk first — every OpenAI client expects this
            $this->emitOpenAiChunk($id, $modelOut, ['role' => 'assistant'], null);

            // Try to find a real-streaming backend through the same
            // resolver Dispatcher uses. If unavailable, synthesise from
            // the batch result.
            $backend = $this->resolveStreamableBackend($options);
            if ($backend !== null) {
                $resolvedOpts = $this->resolvedOptions ?: $options;
                try {
                    foreach ($backend->generateStream($resolvedOpts) as $event) {
                        $type = $event['type'] ?? '';
                        if ($type === 'text') {
                            $this->emitOpenAiChunk($id, $modelOut, ['content' => (string) ($event['delta'] ?? '')], null);
                        } elseif ($type === 'stop') {
                            $this->emitOpenAiChunk($id, $modelOut, [], (string) ($event['reason'] ?? 'stop'));
                            break;
                        }
                        // text_delta / thinking / tool_use_delta / usage:
                        // OpenAI Chat Completions clients only consume
                        // role+content+finish_reason. Drop everything else.
                    }
                    echo "data: [DONE]\n\n";
                    flush();
                    return;
                } catch (\Throwable $e) {
                    // Stream failed mid-flight — fall through to synthetic
                    // fallback so the client gets SOMETHING. The error
                    // surfaces in logs but we don't half-close the SSE.
                    $this->emitOpenAiChunk(
                        $id, $modelOut,
                        ['content' => "\n[stream error: {$e->getMessage()}; falling back to batch]\n"],
                        null
                    );
                }
            }

            // Synthetic fallback: batch the full result, chunk it.
            $result = $this->dispatcher->dispatch($options);
            if ($result === null) {
                echo "data: " . json_encode([
                    'error' => ['message' => 'Dispatcher failed', 'type' => 'server_error'],
                ]) . "\n\n";
                echo "data: [DONE]\n\n";
                return;
            }
            $text = (string) ($result['text'] ?? '');
            foreach (str_split($text, 64) as $piece) {
                $this->emitOpenAiChunk($id, $modelOut, ['content' => $piece], null);
            }
            $this->emitOpenAiChunk($id, $modelOut, [], (string) ($result['stop_reason'] ?? 'stop'));
            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function emitOpenAiChunk(string $id, string $model, array $delta, ?string $finishReason): void
    {
        echo "data: " . json_encode([
            'id' => $id,
            'object'  => 'chat.completion.chunk',
            'created' => time(),
            'model'   => $model,
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }

    /**
     * Resolve options to a streamable backend via the SAME Dispatcher
     * resolution chain that drives one-shot dispatch — explicit backend
     * override → explicit provider_id → routing table by task_type +
     * capability → scope's active provider → default. This means OpenAI
     * compat clients automatically honor combos, multi-account
     * round-robin, capability routing, etc.
     *
     * Returns:
     *   - [backend, callOptions] when the resolved backend implements
     *     StreamableTextBackend (caller streams from it)
     *   - null when batch-only (caller falls back to synthetic chunking)
     */
    private function resolveStreamableBackend(array $options): ?\SuperAICore\Contracts\StreamableTextBackend
    {
        try {
            [$backend, $providerConfig] = $this->dispatcher->resolve($options);
            if ($backend === null) return null;
            if (!$backend instanceof \SuperAICore\Contracts\StreamableTextBackend) return null;

            // Merge resolved provider_config so the streaming call sees
            // the same credentials Dispatcher would have used.
            $options['provider_config'] = array_merge(
                $providerConfig ?? [],
                $options['provider_config'] ?? [],
            );
            // The stream call needs the merged options; cache the merged
            // copy on $this for the outer streamResponse() to read.
            $this->resolvedOptions = $options;
            return $backend;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Cache of options merged with the resolved provider_config. */
    private array $resolvedOptions = [];

    private function modelObj(string $id, string $kind): array
    {
        return [
            'id'         => $id,
            'object'     => 'model',
            'owned_by'   => 'superaicore',
            'created'    => time(),
            'x_kind'     => $kind,
        ];
    }
}
