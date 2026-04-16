<?php

namespace ForgeOmni\AiCore\Services;

use ForgeOmni\AiCore\Contracts\UsageRepository;

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

    protected function isEnabled(): bool
    {
        if (!function_exists('config')) return true;
        return (bool) config('ai-core.usage_tracking.enabled', true);
    }
}
