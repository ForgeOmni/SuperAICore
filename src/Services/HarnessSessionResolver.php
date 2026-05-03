<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Conversation\HarnessImporter;
use SuperAgent\Conversation\Importers\ClaudeCodeImporter;
use SuperAgent\Conversation\Importers\CodexImporter;

/**
 * Wrapper around SDK 0.9.7's `Conversation\HarnessImporter` family
 * (`ClaudeCodeImporter`, `CodexImporter`) so the `/processes` view can
 * surface a "Resume from…" dropdown without coupling to a specific
 * harness's on-disk format.
 *
 * Two consumer-facing methods:
 *
 *   - `listSessions($harness, $limit)` — ranked list of sessions on this
 *     machine for the picker UI. Each row carries enough metadata
 *     (`first_user_message`, `started_at`, `project`) for the operator
 *     to spot the right one without opening it.
 *   - `loadTranscript($harness, $idOrPath)` — serialise the importer's
 *     `Message[]` into a flat array shape the front-end can render. The
 *     transcript is purely informational here — actually re-dispatching
 *     the messages into a SuperAgentBackend `Agent` is host-specific
 *     (different hosts want different routing / provider / system
 *     prompt) so we expose the raw transcript and let the host decide.
 *
 * Hosts that want one-click "resume into provider X" wire a callable in
 * `super-ai-core.resume.on_load`; the controller invokes it after the
 * importer returns and forwards whatever the callable produces back to
 * the front-end as the response payload (e.g. a redirect URL to a
 * pre-filled chat page).
 */
final class HarnessSessionResolver
{
    /** Stable harness keys this resolver knows about. */
    public const HARNESS_CLAUDE = 'claude';
    public const HARNESS_CODEX  = 'codex';

    /** @var array<string, HarnessImporter> */
    private array $importers = [];

    public function __construct()
    {
        if (!interface_exists(HarnessImporter::class)) return;
        if (class_exists(ClaudeCodeImporter::class)) {
            $this->importers[self::HARNESS_CLAUDE] = new ClaudeCodeImporter();
        }
        if (class_exists(CodexImporter::class)) {
            $this->importers[self::HARNESS_CODEX] = new CodexImporter();
        }
    }

    /** @return list<string> harness keys that ship in the bundle and on this host */
    public function availableHarnesses(): array
    {
        return array_keys($this->importers);
    }

    /**
     * @return list<array{
     *   id: string,
     *   path: string,
     *   started_at: string|null,
     *   project: string|null,
     *   message_count: int|null,
     *   first_user_message: string|null,
     * }>
     */
    public function listSessions(string $harness, int $limit = 30): array
    {
        $importer = $this->importers[$harness] ?? null;
        if ($importer === null) return [];
        try {
            $rows = $importer->listSessions(max(1, min(200, $limit)));
        } catch (\Throwable) {
            return [];
        }
        return array_values($rows);
    }

    /**
     * Load a session and return both the transcript and any host-callback
     * payload (when `super-ai-core.resume.on_load` is wired).
     *
     * @return array{
     *   harness: string,
     *   session: string,
     *   transcript: list<array{role:string, content: list<array<string, mixed>>|string}>,
     *   host_payload: mixed,
     * }
     */
    public function loadTranscript(string $harness, string $idOrPath): array
    {
        $importer = $this->importers[$harness] ?? null;
        if ($importer === null) {
            throw new \RuntimeException("Harness importer for '{$harness}' not available.");
        }
        $messages = $importer->load($idOrPath);

        $transcript = [];
        foreach ($messages as $msg) {
            $transcript[] = $this->serializeMessage($msg);
        }

        $hostPayload = null;
        if (function_exists('config')) {
            $cb = config('super-ai-core.resume.on_load');
            if (is_callable($cb)) {
                try {
                    $hostPayload = $cb($harness, $idOrPath, $messages);
                } catch (\Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log('[SuperAICore] resume.on_load callback failed: ' . $e->getMessage());
                    }
                }
            }
        }

        return [
            'harness'      => $harness,
            'session'      => $idOrPath,
            'transcript'   => $transcript,
            'host_payload' => $hostPayload,
        ];
    }

    /**
     * Serialise an SDK `Message` into a transport-friendly shape. We
     * deliberately don't import the Message class here — the SDK type
     * hierarchy is rich (UserMessage / AssistantMessage / ToolResultMessage
     * / SystemMessage) and may grow. Reading `->role` and `->content` covers
     * every concrete subclass at call time without a brittle instanceof
     * cascade.
     *
     * @return array{role:string, content: list<array<string,mixed>>|string}
     */
    private function serializeMessage(object $msg): array
    {
        $role = '';
        if (property_exists($msg, 'role')) {
            $r = $msg->role;
            $role = is_object($r) && method_exists($r, 'value') ? (string) $r->value : (string) $r;
        }
        $content = $msg->content ?? '';
        if (is_string($content)) {
            return ['role' => $role ?: 'user', 'content' => $content];
        }
        if (!is_array($content)) {
            return ['role' => $role ?: 'user', 'content' => []];
        }
        $blocks = [];
        foreach ($content as $b) {
            if (is_object($b) && method_exists($b, 'toArray')) {
                $blocks[] = $b->toArray();
            } elseif (is_array($b)) {
                $blocks[] = $b;
            }
        }
        return ['role' => $role ?: 'user', 'content' => $blocks];
    }
}
