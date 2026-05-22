<?php

declare(strict_types=1);

namespace SuperAICore\Services;

/**
 * Thin Laravel-host facade over SuperAgent's RtkPipeline.
 *
 * Lets host code (controllers, jobs, tests) compress structured tool
 * output without having to construct the pipeline each time. Backed by
 * a per-process singleton so cumulative stats are aggregatable.
 *
 * Usage:
 *
 *     $compressed = app(RtkCompressorService::class)->compress('git_diff', $raw);
 *     $stats = app(RtkCompressorService::class)->stats();
 *
 * Falls back to passthrough (returns the original) when the SuperAgent
 * SDK isn't installed — keeps SuperAICore's "works without SDK" promise.
 */
final class RtkCompressorService
{
    private mixed $pipeline = null;

    public function compress(string $toolName, string $output): string
    {
        if (!class_exists(\SuperAgent\Tools\Compression\RtkPipeline::class)) {
            return $output;
        }
        if ($this->pipeline === null) {
            $this->pipeline = new \SuperAgent\Tools\Compression\RtkPipeline();
        }
        return $this->pipeline->compress($toolName, $output);
    }

    /**
     * Cumulative byte savings since service construction.
     *
     * @return array{bytes_in:int, bytes_out:int, saved_bytes:int, ratio:float}
     */
    public function stats(): array
    {
        if ($this->pipeline === null) {
            return ['bytes_in' => 0, 'bytes_out' => 0, 'saved_bytes' => 0, 'ratio' => 0.0];
        }
        return $this->pipeline->stats();
    }
}
