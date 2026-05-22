<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use Illuminate\Support\Facades\DB;
use SuperAICore\Models\AiProvider;
use SuperAICore\Models\AiProviderAccount;

/**
 * 9Router-borrowed account picker.
 *
 * Strategy: pick the active, non-cooled-down account with the lowest
 * (priority, last_used_at) tuple. Ties broken by oldest last_used_at —
 * pure LRU within a priority band so traffic spreads evenly.
 *
 * Concurrency: uses an atomic UPDATE … WHERE last_used_at <= ? to claim
 * an account, so two simultaneous workers can't pick the same row. If
 * the optimistic claim fails the picker recurses (max 3 retries) before
 * giving up — at which point the dispatcher falls back to the legacy
 * single-account provider row.
 */
final class AccountRoundRobin
{
    /**
     * Pick the next account to use for $providerId, or null when no
     * eligible account exists (caller falls back to provider's
     * built-in single-account credentials).
     */
    public function pick(int $providerId): ?AiProviderAccount
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = AiProviderAccount::query()
                ->where('provider_id', $providerId)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
                })
                ->orderBy('priority')
                ->orderByRaw('last_used_at IS NULL DESC')
                ->orderBy('last_used_at')
                ->first();

            if ($candidate === null) return null;

            $expectedLast = $candidate->last_used_at;
            $affected = AiProviderAccount::query()
                ->where('id', $candidate->id)
                ->where(function ($q) use ($expectedLast) {
                    if ($expectedLast === null) {
                        $q->whereNull('last_used_at');
                    } else {
                        $q->where('last_used_at', $expectedLast);
                    }
                })
                ->update([
                    'last_used_at' => now(),
                    'usage_count'  => DB::raw('usage_count + 1'),
                ]);

            if ($affected === 1) {
                $candidate->refresh();
                return $candidate;
            }
            // Lost the race — retry
        }
        return null;
    }

    /**
     * Mark an account as cooled-down. `seconds` defaults to 600 (10
     * min) which roughly matches Claude Code / Codex rate-limit
     * windows. Pass 0 to clear cooldown.
     */
    public function cooldown(int $accountId, string $reason = 'quota_exceeded', int $seconds = 600): void
    {
        AiProviderAccount::query()
            ->where('id', $accountId)
            ->update([
                'cooldown_until'  => $seconds > 0 ? now()->addSeconds($seconds) : null,
                'cooldown_reason' => $seconds > 0 ? $reason : null,
            ]);
    }

    /**
     * Apply the account's auth_payload onto the existing provider_config
     * so the backend sees the right credentials without knowing about
     * multi-account routing.
     */
    public function applyToConfig(AiProviderAccount $account, array $providerConfig): array
    {
        $payload = $account->auth_payload ?? [];
        if (!is_array($payload)) return $providerConfig;
        foreach ($payload as $k => $v) {
            $providerConfig[$k] = $v;
        }
        $providerConfig['_account_id'] = $account->id;
        $providerConfig['_account_label'] = $account->label;
        return $providerConfig;
    }
}
