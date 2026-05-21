<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SuperAICore\Models\AiSessionShare;
use SuperAICore\Models\AiUsageLog;

/**
 * Host-side queue + push API for session sharing — opencode
 * `share/share-next.ts` port adapted to a Laravel-friendly shape.
 *
 * Two operating modes:
 *
 *   1. REMOTE — `share.remote_url` points at a sharer service. We mint
 *      a share id + secret pair, POST the session's UsageLog rows + any
 *      attached `file_diff_summary` payloads to `<remote_url>/api/shares`,
 *      and store the returned share URL on the row. Subsequent dispatches
 *      that match the same session_id auto-sync (the `sync()` method
 *      pushes only new rows since `synced_at`).
 *
 *   2. LOCAL — when `remote_url` is empty but `local_url_template` is
 *      set, the URL is rendered against `{share_id}` and we treat the
 *      host's own SuperAICore as the share viewer. Useful for
 *      intranet deployments where "share with a colleague" really
 *      means "give them a link to the same Laravel instance".
 *
 * Failure handling: a failed remote push flips the row to `failed` and
 * surfaces the underlying error in `metadata.last_error`. The
 * controller's `show()` endpoint reports the failure so the UI can
 * render a retry button.
 */
class ShareSessionService
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Create (or reuse) a share for the supplied session id. Returns
     * the persisted row; the share URL lives on `$row->share_url`.
     */
    public function create(string $sessionId): AiSessionShare
    {
        $existing = AiSessionShare::query()
            ->where('session_id', $sessionId)
            ->where('status', AiSessionShare::STATUS_ACTIVE)
            ->orderByDesc('created_at')
            ->first();
        if ($existing instanceof AiSessionShare) return $existing;

        $shareId = (string) Str::random(24);
        $secret  = (string) Str::random(48);
        $row = AiSessionShare::create([
            'session_id' => $sessionId,
            'share_id'   => $shareId,
            'secret'     => $secret,
            'status'     => AiSessionShare::STATUS_ACTIVE,
        ]);

        $this->sync($row);
        return $row->fresh() ?? $row;
    }

    /**
     * Push the session's audit trail to the remote sharer (or render the
     * local share URL when no remote is configured). Idempotent — calling
     * `sync()` again only forwards rows newer than `synced_at`.
     */
    public function sync(AiSessionShare $row): bool
    {
        $remoteUrl = (string) (config('super-ai-core.share.remote_url') ?? '');
        $localTpl  = (string) (config('super-ai-core.share.local_url_template') ?? '');
        $secret    = (string) (config('super-ai-core.share.secret') ?? '');

        // Gather session rows newer than synced_at.
        $rowsQ = AiUsageLog::query()
            ->where('metadata->session_id', $row->session_id)
            ->orderBy('created_at');
        if ($row->synced_at !== null) {
            $rowsQ->where('created_at', '>', $row->synced_at);
        }
        $newRows = $rowsQ->limit(500)->get();

        if ($remoteUrl !== '') {
            try {
                $client = new Client(['timeout' => 10]);
                $client->post(rtrim($remoteUrl, '/') . '/api/shares/' . $row->share_id, [
                    'headers' => [
                        'Accept'        => 'application/json',
                        'Content-Type'  => 'application/json',
                        'Authorization' => $secret !== '' ? 'Bearer ' . $secret : 'Bearer ' . $row->secret,
                    ],
                    'json' => [
                        'session_id' => $row->session_id,
                        'rows'       => $newRows->map(fn ($r) => $r->toArray())->all(),
                    ],
                ]);
                $row->remote_url = $remoteUrl;
                $row->share_url  = rtrim($remoteUrl, '/') . '/shares/' . $row->share_id;
                $row->synced_at  = now();
                $row->save();
                return true;
            } catch (\Throwable $e) {
                $this->logger?->warning('ShareSessionService: remote push failed: ' . $e->getMessage(), [
                    'session_id' => $row->session_id,
                    'remote'     => $remoteUrl,
                ]);
                $row->status = AiSessionShare::STATUS_FAILED;
                $row->metadata = array_merge((array) $row->metadata, ['last_error' => $e->getMessage()]);
                $row->save();
                return false;
            }
        }

        // Local mode — render share_url against the template.
        if ($localTpl !== '') {
            $row->share_url = str_replace('{share_id}', $row->share_id, $localTpl);
            $row->synced_at = now();
            $row->save();
            return true;
        }

        // No remote, no local template — fail loud rather than silently
        // create a share row that no human can ever open.
        $row->status = AiSessionShare::STATUS_FAILED;
        $row->metadata = array_merge((array) $row->metadata, [
            'last_error' => 'No remote_url or local_url_template configured; share has no URL.',
        ]);
        $row->save();
        return false;
    }

    /**
     * Revoke a share. Best-effort: remote DELETE may fail, in which case
     * we still flip the local row to revoked so the dashboard stops
     * surfacing the link.
     */
    public function destroy(AiSessionShare $row): bool
    {
        $remoteUrl = (string) ($row->remote_url ?? config('super-ai-core.share.remote_url') ?? '');
        $secret    = (string) (config('super-ai-core.share.secret') ?? $row->secret);

        if ($remoteUrl !== '') {
            try {
                $client = new Client(['timeout' => 10]);
                $client->delete(rtrim($remoteUrl, '/') . '/api/shares/' . $row->share_id, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $secret,
                        'Accept'        => 'application/json',
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->logger?->info('ShareSessionService: remote destroy returned error (ignored): ' . $e->getMessage());
            }
        }
        $row->status = AiSessionShare::STATUS_REVOKED;
        $row->save();
        return true;
    }
}
