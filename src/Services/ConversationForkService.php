<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Conversation\Fork;
use SuperAgent\Messages\Message;

/**
 * Thin host wrapper around SDK 0.9.8 `Conversation\Fork` — codex `/side`
 * semantics as a host service so the chat UI can offer "branch this
 * conversation, try a different model on the side, promote only the
 * useful side messages back."
 *
 * Two consumer methods, mirroring the SDK contract:
 *
 *   - `start(messages)` — snapshot the current message list, return a
 *      fork handle the caller stores under whatever id makes sense
 *      (e.g. a UUID embedded in the URL).
 *   - `finish($fork, $action, $indexes?)` — collapse the fork. Three
 *     actions:
 *       - 'discard'    → throw the side away; parent is untouched.
 *       - 'promote'    → bring specific side messages back into the
 *                        parent (`indexes`).
 *       - 'promoteAll' → bring everything back.
 *
 * The service stores nothing — fork lifetime is the host's call. We
 * just provide a typed seam so hosts don't have to import SDK classes
 * everywhere.
 */
final class ConversationForkService
{
    /**
     * @param Message[] $parentMessages
     */
    public function start(array $parentMessages): ?Fork
    {
        if (!class_exists(Fork::class)) return null;
        return Fork::from($parentMessages);
    }

    /**
     * @param  Fork                  $fork
     * @param  'discard'|'promote'|'promoteAll' $action
     * @param  list<int>             $indexes  (only used with `promote`)
     * @return Message[]                       The new parent message list
     */
    public function finish(Fork $fork, string $action, array $indexes = []): array
    {
        return match ($action) {
            'discard'    => $fork->discard(),
            'promote'    => $fork->promote(...$indexes),
            'promoteAll' => $fork->promoteAll(),
            default      => throw new \InvalidArgumentException("Unknown fork action: {$action}"),
        };
    }
}
