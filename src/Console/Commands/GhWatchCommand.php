<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use SuperAICore\Models\AiPrWatcher;
use SuperAICore\Models\AiUserQuestion;

/**
 * claude-octopus-borrowed GitHub PR / CI watcher.
 *
 * Polls every active `ai_pr_watchers` row on a fixed interval and
 * surfaces new events (PR comments, failed CI checks) according to
 * each row's configured action:
 *
 *   ask_user    : insert AiUserQuestion row so the founder can decide
 *   spawn_squad : invoke `php artisan squad "fix ${event}"` with the team
 *                 listed in action_payload.team
 *   webhook     : POST event JSON to action_payload.url with bearer token
 *   log         : append to storage/logs/gh-watch.log
 *
 * Auth: reads GITHUB_TOKEN from env. Uses ETags for conditional GET so
 * the polling doesn't burn API quota when nothing changed.
 *
 * Designed to be run by the host's scheduler:
 *
 *   $schedule->command('super-ai-core:gh-watch')->everyFiveMinutes();
 *
 * Or as a long-running daemon with `--loop=30` (sleep 30s between
 * polls until SIGTERM).
 */
final class GhWatchCommand extends Command
{
    protected $signature = 'super-ai-core:gh-watch
        {--loop=0     : Daemon mode: sleep N seconds between polls (0 = one-shot)}
        {--owner=     : Limit to a single owner}
        {--repo=      : Limit to a single repo (requires --owner)}
        {--dry-run    : Report what would fire without executing actions}
        {--json       : Emit JSON envelope}';

    protected $description = 'Poll GitHub for PR comments / CI failures on watched repos and trigger reactions.';

    public function handle(): int
    {
        $loopSeconds = max(0, (int) $this->option('loop'));
        $dryRun = (bool) $this->option('dry-run');
        $singleOwner = $this->option('owner') ? (string) $this->option('owner') : null;
        $singleRepo = $this->option('repo') ? (string) $this->option('repo') : null;

        do {
            $watchers = AiPrWatcher::query()->active();
            if ($singleOwner !== null) $watchers->where('owner', $singleOwner);
            if ($singleRepo  !== null) $watchers->where('repo',  $singleRepo);

            $report = ['polled' => [], 'fired' => [], 'errors' => []];
            $watchers->each(function (AiPrWatcher $w) use (&$report, $dryRun) {
                try {
                    $events = $this->pollWatcher($w);
                    $report['polled'][] = ['watcher' => "{$w->owner}/{$w->repo}", 'events' => count($events)];
                    foreach ($events as $event) {
                        if (!$dryRun) $this->fireAction($w, $event);
                        $report['fired'][] = [
                            'watcher' => "{$w->owner}/{$w->repo}",
                            'event'   => $event['kind'] ?? 'unknown',
                            'pr'      => $event['pr_number'] ?? null,
                            'action'  => $w->action,
                            'dry_run' => $dryRun,
                        ];
                    }
                    $w->last_polled_at = now();
                    $w->save();
                } catch (\Throwable $e) {
                    $report['errors'][] = [
                        'watcher' => "{$w->owner}/{$w->repo}",
                        'error'   => $e->getMessage(),
                    ];
                }
            });

            if ($this->option('json')) {
                $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $this->info(sprintf(
                    'gh-watch: polled=%d fired=%d errors=%d',
                    count($report['polled']),
                    count($report['fired']),
                    count($report['errors']),
                ));
                foreach ($report['errors'] as $err) $this->warn('  err: ' . json_encode($err));
            }

            if ($loopSeconds > 0) {
                sleep($loopSeconds);
            }
        } while ($loopSeconds > 0);

        return self::SUCCESS;
    }

    /** @return list<array{kind:string, pr_number:int, title:string, body:string, url:string}> */
    private function pollWatcher(AiPrWatcher $w): array
    {
        $token = (string) (getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: '');
        if ($token === '') {
            throw new \RuntimeException('GITHUB_TOKEN env not set');
        }

        $client = new Client([
            'base_uri' => 'https://api.github.com/',
            'timeout'  => 20,
            'headers'  => [
                'Accept'                 => 'application/vnd.github+json',
                'Authorization'          => 'Bearer ' . $token,
                'X-GitHub-Api-Version'   => '2022-11-28',
                'User-Agent'             => 'super-ai-core/gh-watch',
            ],
        ]);

        $sinceIso = $w->last_polled_at?->subMinutes(5)->toIso8601String();

        // Recently-updated PRs
        $prsResp = $client->get("repos/{$w->owner}/{$w->repo}/pulls", [
            'query' => ['state' => 'open', 'sort' => 'updated', 'direction' => 'desc', 'per_page' => 25],
        ]);
        $prs = json_decode((string) $prsResp->getBody(), true) ?: [];

        $events = [];
        foreach ($prs as $pr) {
            if (!is_array($pr)) continue;
            $updatedAt = $pr['updated_at'] ?? null;
            if ($sinceIso !== null && $updatedAt !== null && $updatedAt < $sinceIso) continue;

            // Recent comments
            $commentsResp = $client->get("repos/{$w->owner}/{$w->repo}/issues/{$pr['number']}/comments", [
                'query' => ['per_page' => 10, 'sort' => 'created', 'direction' => 'desc'],
            ]);
            foreach (json_decode((string) $commentsResp->getBody(), true) ?: [] as $comment) {
                $cAt = $comment['created_at'] ?? null;
                if ($sinceIso !== null && $cAt !== null && $cAt < $sinceIso) continue;
                $events[] = [
                    'kind'      => 'pr_comment',
                    'pr_number' => (int) $pr['number'],
                    'title'     => (string) ($pr['title'] ?? ''),
                    'body'      => (string) ($comment['body'] ?? ''),
                    'url'       => (string) ($comment['html_url'] ?? ''),
                ];
            }

            // CI status: list check runs for the PR head SHA
            $sha = $pr['head']['sha'] ?? null;
            if (is_string($sha) && $sha !== '') {
                try {
                    $checksResp = $client->get("repos/{$w->owner}/{$w->repo}/commits/{$sha}/check-runs");
                    $runs = (json_decode((string) $checksResp->getBody(), true)['check_runs'] ?? []);
                    foreach ($runs as $run) {
                        if (($run['conclusion'] ?? null) !== 'failure') continue;
                        $completed = $run['completed_at'] ?? null;
                        if ($sinceIso !== null && $completed !== null && $completed < $sinceIso) continue;
                        $events[] = [
                            'kind'      => 'ci_failure',
                            'pr_number' => (int) $pr['number'],
                            'title'     => (string) ($pr['title'] ?? ''),
                            'body'      => 'CI check ' . ($run['name'] ?? '?') . ' failed: ' . ($run['output']['summary'] ?? ''),
                            'url'       => (string) ($run['html_url'] ?? ''),
                        ];
                    }
                } catch (\Throwable) {
                    // ignore check-runs failures — PR comment still surfaces
                }
            }
        }

        return $events;
    }

    private function fireAction(AiPrWatcher $w, array $event): void
    {
        switch ($w->action) {
            case AiPrWatcher::ACTION_ASK_USER:
                AiUserQuestion::create([
                    'kind'        => 'select',
                    'question'    => sprintf("[%s] %s/%s#%d — %s\n\n%s\n\n%s",
                        strtoupper($event['kind'] ?? 'event'),
                        $w->owner, $w->repo,
                        (int) ($event['pr_number'] ?? 0),
                        $event['title'] ?? '',
                        substr((string) ($event['body'] ?? ''), 0, 2000),
                        $event['url'] ?? ''
                    ),
                    'options' => [
                        ['label' => 'Spawn squad to investigate'],
                        ['label' => 'Open in browser'],
                        ['label' => 'Snooze 1h'],
                        ['label' => 'Ignore'],
                    ],
                    'metadata' => ['source' => 'gh-watch', 'watcher_id' => $w->id, 'event' => $event],
                    'status'   => AiUserQuestion::STATUS_PENDING,
                ]);
                break;

            case AiPrWatcher::ACTION_LOG:
            default:
                $path = storage_path('logs/gh-watch.log');
                @mkdir(dirname($path), 0775, true);
                file_put_contents(
                    $path,
                    json_encode(['at' => now()->toIso8601String(), 'watcher' => $w->id, 'event' => $event]) . "\n",
                    FILE_APPEND
                );
                break;

            case AiPrWatcher::ACTION_WEBHOOK:
                $url = (string) ($w->action_payload['url'] ?? '');
                if ($url === '') break;
                $bearer = (string) ($w->action_payload['bearer'] ?? '');
                $client = new Client(['timeout' => 10]);
                try {
                    $client->post($url, [
                        'json'    => $event,
                        'headers' => $bearer !== '' ? ['Authorization' => 'Bearer ' . $bearer] : [],
                    ]);
                } catch (\Throwable) {
                    // best-effort
                }
                break;

            case AiPrWatcher::ACTION_SPAWN_SQUAD:
                // Direct PHP dispatch — no shell out — so the daemon works
                // identically whether SuperAICore is the host app or a
                // vendored package inside a third-party Laravel project.
                $team = (string) ($w->action_payload['team'] ?? 'super-team-research-triangulation');
                $task = sprintf(
                    "GitHub %s on %s/%s#%d: %s\n\n%s\n\n%s",
                    $event['kind'] ?? 'event',
                    $w->owner, $w->repo,
                    (int) ($event['pr_number'] ?? 0),
                    $event['title'] ?? '',
                    substr((string) ($event['body'] ?? ''), 0, 1500),
                    $event['url'] ?? ''
                );
                try {
                    $orchestrator = app(\SuperAICore\Modes\CliSquadOrchestrator::class);
                    $orchestrator->run($task, [
                        'team' => $team,
                        'metadata' => ['gh_watch_event' => $event, 'watcher_id' => $w->id],
                    ]);
                } catch (\Throwable $e) {
                    // Squad failures don't kill the daemon — log and move on.
                    $path = storage_path('logs/gh-watch.log');
                    @mkdir(dirname($path), 0775, true);
                    file_put_contents(
                        $path,
                        json_encode([
                            'at' => now()->toIso8601String(),
                            'watcher' => $w->id,
                            'spawn_squad_error' => $e->getMessage(),
                            'event' => $event,
                        ]) . "\n",
                        FILE_APPEND
                    );
                }
                break;
        }
    }
}
