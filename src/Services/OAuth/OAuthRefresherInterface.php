<?php

declare(strict_types=1);

namespace SuperAICore\Services\OAuth;

use SuperAICore\Models\AiProviderAccount;

/**
 * 9Router-borrowed per-account OAuth refresher contract.
 *
 * One implementation per OAuth provider (Claude / Codex / Copilot / Kiro).
 * Refreshers operate on a single AiProviderAccount: read its refresh
 * token from auth_payload, call the provider's token endpoint, and
 * write back the new access_token + expires_at into auth_payload.
 *
 * Refreshers do NOT touch the legacy single-account ai_providers row —
 * that path still goes through the CLI global refresh in
 * OAuthRefreshCommand::refreshOAuth(). Per-account is a separate code
 * path so the two don't interfere.
 *
 * Implementations live in `Refreshers/`:
 *   - ClaudeRefresher  → Anthropic OAuth token endpoint
 *   - CodexRefresher   → OpenAI codex OAuth token endpoint
 *   - CopilotRefresher → GitHub Copilot session token endpoint
 *   - KiroRefresher    → Kiro's auth endpoint
 *
 * If a provider's wire format isn't documented or changes, the
 * implementation MAY return false to indicate "I can't refresh this
 * one — fall back to CLI global refresh" so the daemon stays useful
 * even with partial coverage.
 */
interface OAuthRefresherInterface
{
    /**
     * Try to refresh $account's access token. Returns true on success
     * (and writes back auth_payload + saves the model). Returns false
     * when the refresh failed or this refresher can't handle the
     * payload shape (caller falls back to CLI global refresh).
     */
    public function refresh(AiProviderAccount $account): bool;

    /**
     * The backend name this refresher handles (claude_cli / codex_cli /
     * copilot_cli / kiro_cli). Used by the OAuthRefreshCommand to
     * pick the right refresher for each account.
     */
    public function backendName(): string;
}
