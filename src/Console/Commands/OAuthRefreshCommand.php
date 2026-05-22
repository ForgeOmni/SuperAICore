<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use Illuminate\Console\Command;
use SuperAICore\Models\AiProvider;
use SuperAICore\Models\AiProviderAccount;

/**
 * 9Router-borrowed OAuth refresh daemon.
 *
 * Scans every provider with auth_mode = 'oauth' (claude_cli, codex_cli,
 * copilot_cli, kiro_cli, ...) plus every ai_provider_accounts row with
 * an OAuth refresh token, and refreshes any token expiring in the next
 * `--lookahead` seconds (default 600 = 10 min).
 *
 * Wire into the scheduler:
 *
 *   $schedule->command('super-ai-core:oauth-refresh')->everyTenMinutes();
 *
 * (Kernel.php registration is the host's responsibility — the package
 * doesn't define a kernel.)
 *
 * Refresh logic per provider type:
 *   - claude_cli: shells out to `claude /refresh` (idempotent)
 *   - codex_cli:  `codex auth refresh`
 *   - copilot_cli: GET https://api.github.com/copilot_internal/v2/token
 *                  with X-Github-Token header (the refresh endpoint)
 *
 * Non-OAuth providers are silently skipped — this command is safe to
 * cron even when nothing needs refreshing.
 */
final class OAuthRefreshCommand extends Command
{
    protected $signature = 'super-ai-core:oauth-refresh
        {--lookahead=600 : Seconds-ahead window: refresh tokens expiring within this window}
        {--dry-run       : Report what would be refreshed without calling provider refresh endpoints}
        {--json          : Emit JSON envelope}
        {--provider=     : Limit to one provider type (claude_cli / codex_cli / copilot_cli / ...)}';

    protected $description = 'Pre-emptively refresh OAuth tokens for subscription providers (9Router-borrowed).';

    public function handle(): int
    {
        $lookahead = max(60, (int) $this->option('lookahead'));
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('provider') ? (string) $this->option('provider') : null;

        $report = [
            'lookahead_seconds' => $lookahead,
            'dry_run'           => $dryRun,
            'started_at'        => now()->toIso8601String(),
            'refreshed'         => [],
            'skipped'           => [],
            'errors'            => [],
        ];

        // Walk providers (the singleton-account legacy rows)
        $providers = AiProvider::query()->where('is_active', true);
        if ($only !== null) {
            $providers->where('backend', $only);
        }
        $providers->each(function (AiProvider $p) use (&$report, $lookahead, $dryRun) {
            $backend = (string) ($p->backend ?? '');
            if (!$this->isOAuthBackend($backend)) {
                $report['skipped'][] = ['provider_id' => $p->id, 'reason' => 'non-oauth backend'];
                return;
            }

            $expiresAt = $this->extractExpiry($p->extra_config ?? []);
            if ($expiresAt !== null && $expiresAt - time() > $lookahead) {
                $report['skipped'][] = [
                    'provider_id' => $p->id,
                    'reason'      => 'expires_in=' . ($expiresAt - time()) . 's',
                ];
                return;
            }

            if ($dryRun) {
                $report['refreshed'][] = ['provider_id' => $p->id, 'backend' => $backend, 'dry_run' => true];
                return;
            }

            try {
                $ok = $this->refreshOAuth($backend);
                if ($ok) {
                    $report['refreshed'][] = ['provider_id' => $p->id, 'backend' => $backend];
                } else {
                    $report['errors'][] = ['provider_id' => $p->id, 'backend' => $backend, 'error' => 'refresh returned false'];
                }
            } catch (\Throwable $e) {
                $report['errors'][] = ['provider_id' => $p->id, 'backend' => $backend, 'error' => $e->getMessage()];
            }
        });

        // Multi-account dimension (ai_provider_accounts)
        if (class_exists(AiProviderAccount::class)) {
            try {
                AiProviderAccount::query()->where('is_active', true)->each(function (AiProviderAccount $a) use (&$report, $lookahead, $dryRun, $only) {
                    $provider = $a->provider;
                    if ($provider === null) return;
                    if ($only !== null && $provider->backend !== $only) return;
                    if (!$this->isOAuthBackend((string) $provider->backend)) return;

                    $expiresAt = $this->extractExpiry($a->auth_payload ?? []);
                    if ($expiresAt !== null && $expiresAt - time() > $lookahead) {
                        $report['skipped'][] = ['account_id' => $a->id, 'reason' => 'expires_in=' . ($expiresAt - time()) . 's'];
                        return;
                    }

                    if ($dryRun) {
                        $report['refreshed'][] = ['account_id' => $a->id, 'backend' => $provider->backend, 'dry_run' => true];
                        return;
                    }

                    try {
                        $ok = $this->refreshAccount($a);
                        if ($ok) {
                            $report['refreshed'][] = ['account_id' => $a->id, 'backend' => $provider->backend];
                        }
                    } catch (\Throwable $e) {
                        $report['errors'][] = ['account_id' => $a->id, 'error' => $e->getMessage()];
                    }
                });
            } catch (\Throwable) {
                // Migration not run yet — silently skip multi-account dimension
            }
        }

        $report['finished_at'] = now()->toIso8601String();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->info(sprintf(
                'OAuth refresh: refreshed=%d skipped=%d errors=%d',
                count($report['refreshed']),
                count($report['skipped']),
                count($report['errors']),
            ));
            foreach ($report['errors'] as $err) {
                $this->warn('  err: ' . json_encode($err));
            }
        }

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function isOAuthBackend(string $backend): bool
    {
        return in_array($backend, [
            'claude_cli', 'codex_cli', 'copilot_cli', 'kiro_cli',
        ], true);
    }

    /** Extract expiry epoch from a config / auth payload. Returns null if unknown. */
    private function extractExpiry(array $payload): ?int
    {
        foreach (['expires_at', 'token_expires_at', 'access_token_expires_at'] as $k) {
            if (!empty($payload[$k])) return (int) $payload[$k];
        }
        // RFC3339 string
        foreach (['expires_at_iso', 'expires_iso'] as $k) {
            if (!empty($payload[$k])) {
                $t = strtotime((string) $payload[$k]);
                if ($t !== false) return $t;
            }
        }
        return null;
    }

    /**
     * Shell out to the matching CLI's refresh subcommand. Returns true
     * when the CLI exited 0 (or said "still valid"), false otherwise.
     */
    private function refreshOAuth(string $backend): bool
    {
        $cmd = match ($backend) {
            'claude_cli'   => ['claude', '/refresh'],
            'codex_cli'    => ['codex', 'auth', 'refresh'],
            'copilot_cli'  => ['gh', 'copilot', 'refresh'],
            'kiro_cli'     => ['kiro', 'refresh'],
            default        => null,
        };
        if ($cmd === null) return false;
        $proc = new \Symfony\Component\Process\Process($cmd);
        $proc->setTimeout(60);
        $proc->run();
        return $proc->isSuccessful();
    }

    private function refreshAccount(AiProviderAccount $a): bool
    {
        $backend = (string) ($a->provider?->backend ?? '');
        if ($backend === '') return false;

        // Try the per-account refresher first (real OAuth refresh
        // token endpoint, no CLI subprocess). Falls back to the
        // CLI-global refresh when the refresher can't handle the
        // payload (e.g. API-key account with no refresh_token).
        try {
            $registry = function_exists('app')
                ? app(\SuperAICore\Services\OAuth\OAuthRefresherRegistry::class)
                : new \SuperAICore\Services\OAuth\OAuthRefresherRegistry();
            $refresher = $registry->for($backend);
            if ($refresher !== null && $refresher->refresh($a)) {
                return true;
            }
        } catch (\Throwable) {
            // Registry not bound or refresher threw — fall through.
        }
        return $this->refreshOAuth($backend);
    }
}
