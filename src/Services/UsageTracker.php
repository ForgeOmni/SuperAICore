<?php

namespace SuperAICore\Services;

use SuperAICore\Contracts\UsageRepository;

class UsageTracker
{
    public function __construct(protected ?UsageRepository $repo = null) {}

    public function record(array $data): ?int
    {
        if (!$this->repo) return null;
        if (!($this->isEnabled())) return null;
        try {
            return $this->repo->record($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function summary(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        if (!$this->repo) return ['total_runs' => 0, 'total_cost_usd' => 0];
        return $this->repo->summary($from, $to);
    }

    public function recent(int $limit = 50, array $filters = []): array
    {
        if (!$this->repo) return [];
        return $this->repo->recent($limit, $filters);
    }

    /**
     * Forwarding method so `Dispatcher::detectCacheCold()` can reach
     * the lookup without resolving the repository directly. Mirrors the
     * UsageRepository contract — returns null when the repo doesn't
     * implement the optional method (older host implementations).
     *
     * @param  list<string> $backends
     * @return array{id:int, backend:string, model:string, created_at:string}|null
     */
    public function findLatestForSession(string $sessionId, array $backends): ?array
    {
        if (!$this->repo) return null;
        if (!method_exists($this->repo, 'findLatestForSession')) return null;
        try {
            return $this->repo->findLatestForSession($sessionId, $backends);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isEnabled(): bool
    {
        if (!function_exists('config')) return true;
        return (bool) config('super-ai-core.usage_tracking.enabled', true);
    }
}
