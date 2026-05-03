<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SuperAICore\Services\HarnessSessionResolver;

/**
 * Cross-harness session resume — borrowed from jcode, lands here as
 * the SuperAICore half of SuperAgent SDK 0.9.7's `HarnessImporter` SPI.
 *
 * The /processes view's "Resume from…" dropdown calls these endpoints
 * to enumerate Claude Code / Codex sessions on this machine and pull
 * the transcript for the chosen session. Hosts wire
 * `super-ai-core.resume.on_load` (callable) to actually re-dispatch the
 * messages into one of their backends; without that, the endpoints
 * return the transcript JSON for inspection / copy-paste.
 *
 * Gated by `super-ai-core.resume.enabled` so hosts on shared machines
 * don't accidentally expose every operator's `~/.claude` history to
 * the dashboard.
 */
class HarnessResumeController extends Controller
{
    public function __construct(
        protected HarnessSessionResolver $resolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (!$this->enabled()) {
            return response()->json(['enabled' => false, 'harnesses' => []]);
        }
        return response()->json([
            'enabled'    => true,
            'harnesses'  => $this->resolver->availableHarnesses(),
        ]);
    }

    public function listSessions(Request $request, string $harness): JsonResponse
    {
        if (!$this->enabled()) {
            return response()->json(['error' => 'resume_disabled'], 403);
        }
        $limit = (int) $request->input('limit', 30);
        $rows = $this->resolver->listSessions($harness, $limit);
        return response()->json(['harness' => $harness, 'sessions' => $rows]);
    }

    public function load(Request $request, string $harness): JsonResponse
    {
        if (!$this->enabled()) {
            return response()->json(['error' => 'resume_disabled'], 403);
        }
        $session = (string) $request->input('session', '');
        if ($session === '') {
            return response()->json(['error' => 'missing_session'], 422);
        }
        try {
            $payload = $this->resolver->loadTranscript($harness, $session);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'load_failed', 'message' => $e->getMessage()], 422);
        }
        return response()->json($payload);
    }

    protected function enabled(): bool
    {
        return (bool) (function_exists('config')
            ? config('super-ai-core.resume.enabled', false)
            : false);
    }
}
