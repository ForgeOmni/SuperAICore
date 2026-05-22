<?php

declare(strict_types=1);

namespace SuperAICore\Services\Tools;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;
use SuperAICore\Models\AiUserQuestion;

/**
 * Mid-run HITL "ask the user a clarifying question" tool.
 *
 * Modeled on opencode's `tool/question.ts`. The model emits a tool call
 * with the question + optional N predefined answer choices; this tool
 * inserts an `ai_user_questions` row in state `pending`, then polls the
 * row (sleep 500ms in a loop) until either:
 *
 *   - an operator POSTs `/processes/questions/{id}/answer` (status →
 *     `answered`, answer populated), or
 *   - `timeout_seconds` elapses (status → `timed_out`, returned as an
 *     error tool result so the model gets clean feedback instead of an
 *     indefinite block).
 *
 * The polling-vs-deferred design choice mirrors how SuperAICore already
 * works (DB-backed processes table polled by `/processes` SSE); we don't
 * need a long-lived in-process callback registry because the answer
 * round-trips through HTTP regardless.
 */
class AskUserTool extends Tool
{
    /** Default time to wait for an answer before bailing out. */
    private const DEFAULT_TIMEOUT_SECONDS = 600;

    /** Hard upper bound — protects against the model passing an int_max. */
    private const MAX_TIMEOUT_SECONDS = 3600;

    /** Sleep between DB checks. 500ms balances latency against load. */
    private const POLL_INTERVAL_MS = 500;

    /**
     * Optional dispatch context — SuperAgentBackend stamps this on the
     * row so the UI can scope questions to a specific process / session.
     */
    public function __construct(
        private readonly ?string $sessionId = null,
        private readonly ?string $processId = null,
        private readonly ?string $agentLabel = null,
    ) {}

    public function name(): string
    {
        return 'ask_user';
    }

    public function description(): string
    {
        return <<<'TXT'
Ask the human operator a clarifying question and wait for their reply.

Use this when you need a decision the user has not yet supplied — pick
between two implementation approaches, confirm a destructive action,
choose a model variant, etc. Do NOT use this for guesses the user could
infer from the conversation; it interrupts the operator.

`question` is the prompt rendered to the user. `options` is an optional
list of pre-defined answers; when present, the UI renders them as
buttons and the answer string will be one of the supplied labels. When
absent the UI renders a free-form text field.

Returns the user's answer as plain text, or an error if the user
cancelled or the wait timed out.
TXT;
    }

    public function category(): string
    {
        return 'interactive';
    }

    public function requiresUserInteraction(): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => [
                    'type' => 'string',
                    'enum' => AiUserQuestion::KINDS,
                    'description' => 'Dialog kind (Pi Extension UI protocol). `select` = button row from `options`. `confirm` = yes/no. `input` = single-line text. `editor` = multi-line text. Defaults to `select` when `options` is set, else `input`.',
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'The clarifying question to put in front of the user.',
                ],
                'options' => [
                    'type' => 'array',
                    'description' => 'For kind=select: list of predefined answer choices. Ignored for confirm/input/editor.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label'       => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['label'],
                    ],
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'How long to wait before giving up. Defaults to 600 (10 minutes). Capped at 3600.',
                    'minimum' => 1,
                    'maximum' => self::MAX_TIMEOUT_SECONDS,
                ],
            ],
            'required' => ['question'],
        ];
    }

    private function resolveKind(array $input, array $options): string
    {
        $kind = (string) ($input['kind'] ?? '');
        if (in_array($kind, AiUserQuestion::KINDS, true)) return $kind;
        return !empty($options) ? AiUserQuestion::KIND_SELECT : AiUserQuestion::KIND_INPUT;
    }

    public function execute(array $input): ToolResult
    {
        $question = (string) ($input['question'] ?? '');
        if ($question === '') {
            return ToolResult::error('ask_user: `question` is required.');
        }
        $options = $input['options'] ?? [];
        if (!is_array($options)) $options = [];

        $timeout = (int) ($input['timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        if ($timeout < 1) $timeout = self::DEFAULT_TIMEOUT_SECONDS;
        if ($timeout > self::MAX_TIMEOUT_SECONDS) $timeout = self::MAX_TIMEOUT_SECONDS;

        $kind = $this->resolveKind($input, $options);
        if ($kind === AiUserQuestion::KIND_CONFIRM) {
            // confirm is two fixed buttons; honor any model-supplied labels
            // but default to Yes/No for renderer simplicity.
            if ($options === []) {
                $options = [
                    ['label' => 'Yes'],
                    ['label' => 'No'],
                ];
            }
        }

        try {
            $row = AiUserQuestion::create([
                'session_id'  => $this->sessionId,
                'process_id'  => $this->processId,
                'agent_label' => $this->agentLabel,
                'kind'        => $kind,
                'question'    => $question,
                'options'     => $options,
                'status'      => AiUserQuestion::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('ask_user: could not persist question: ' . $e->getMessage());
        }

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            usleep(self::POLL_INTERVAL_MS * 1000);
            $fresh = AiUserQuestion::find($row->id);
            if ($fresh === null) {
                return ToolResult::error('ask_user: question record was deleted before answer.');
            }
            if ($fresh->status === AiUserQuestion::STATUS_ANSWERED) {
                $answer = (string) ($fresh->answer ?? '');
                return ToolResult::success($answer === '' ? '(empty answer)' : $answer);
            }
            if ($fresh->status === AiUserQuestion::STATUS_CANCELLED) {
                return ToolResult::error('ask_user: user cancelled the question.');
            }
        }

        // Best-effort transition to timed_out so the UI can render the
        // expired card with a clear label instead of indefinite spinner.
        try {
            $row->status = AiUserQuestion::STATUS_TIMED_OUT;
            $row->save();
        } catch (\Throwable) {
            // Don't crash the agent on a DB failure during cleanup.
        }
        return ToolResult::error("ask_user: no answer within {$timeout}s timeout.");
    }
}
