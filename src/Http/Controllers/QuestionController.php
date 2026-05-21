<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SuperAICore\Models\AiUserQuestion;

/**
 * Mid-run HITL question endpoint — backs `AskUserTool` and the
 * `/processes` question card.
 *
 * Three operations:
 *
 *   - GET  `/processes/questions`            — list pending questions
 *                                              (optionally filtered by
 *                                              `process_id` / `session_id`)
 *   - POST `/processes/questions/{id}/answer` — body `{answer: string}`,
 *                                              flips status to `answered`
 *                                              and unblocks the polling
 *                                              AskUserTool
 *   - POST `/processes/questions/{id}/cancel` — body empty, flips status
 *                                              to `cancelled` so the tool
 *                                              returns an error result
 */
class QuestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AiUserQuestion::query()
            ->where('status', AiUserQuestion::STATUS_PENDING)
            ->orderBy('created_at');

        $processId = (string) $request->query('process_id', '');
        if ($processId !== '') $q->where('process_id', $processId);

        $sessionId = (string) $request->query('session_id', '');
        if ($sessionId !== '') $q->where('session_id', $sessionId);

        $rows = $q->limit(50)->get();
        $out = $rows->map(fn ($r) => [
            'id'          => $r->id,
            'session_id'  => $r->session_id,
            'process_id'  => $r->process_id,
            'agent_label' => $r->agent_label,
            'question'    => $r->question,
            'options'     => $r->options ?? [],
            'created_at'  => $r->created_at?->toIso8601String(),
        ])->all();

        return response()->json(['questions' => $out]);
    }

    public function answer(Request $request, int $id): JsonResponse
    {
        $row = AiUserQuestion::find($id);
        if ($row === null) {
            return response()->json(['ok' => false, 'message' => 'Question not found.'], 404);
        }
        if (!$row->isPending()) {
            return response()->json(['ok' => false, 'message' => 'Question is not pending (status: ' . $row->status . ').'], 409);
        }

        $answer = (string) $request->input('answer', '');
        if ($answer === '') {
            return response()->json(['ok' => false, 'message' => '`answer` is required.'], 422);
        }

        $row->answer      = $answer;
        $row->status      = AiUserQuestion::STATUS_ANSWERED;
        $row->answered_at = now();
        $row->save();

        return response()->json(['ok' => true, 'message' => 'Answer recorded.']);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = AiUserQuestion::find($id);
        if ($row === null) {
            return response()->json(['ok' => false, 'message' => 'Question not found.'], 404);
        }
        if (!$row->isPending()) {
            return response()->json(['ok' => false, 'message' => 'Question is not pending (status: ' . $row->status . ').'], 409);
        }

        $row->status      = AiUserQuestion::STATUS_CANCELLED;
        $row->answered_at = now();
        $row->save();

        return response()->json(['ok' => true, 'message' => 'Question cancelled.']);
    }
}
