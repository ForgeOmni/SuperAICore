<?php

namespace SuperAICore\Support;

use Carbon\Carbon;

/**
 * Read-only row for SuperAICore's Process Monitor view. The view only reads
 * public properties — mirrors the shape of the AiProcess model so templates
 * stay identical whether the row came from the ai_processes table or a
 * host-app ProcessSource (TaskResult, Job, etc.).
 */
class ProcessEntry
{
    public function __construct(
        /** Composite id, form "{sourceKey}.{localId}" — used as URL segment. */
        public readonly string $id,
        public readonly ?int $pid,
        public readonly string $backend,
        public readonly string $status,
        public readonly ?string $external_label = null,
        public readonly ?string $external_id = null,
        public readonly ?string $command = null,
        public readonly ?Carbon $started_at = null,
        public readonly ?string $log_file = null,

        // ─── Rich host-contributed fields (all optional) ───
        // When a host source populates these, the process-monitor view renders
        // a SuperTeam-style row with task/project/provider/model badges.
        public readonly ?int $result_id = null,
        public readonly ?int $task_id = null,
        public readonly ?string $task_name = null,
        public readonly ?string $task_type = null,
        public readonly ?string $project_name = null,
        public readonly ?string $language = null,
        public readonly ?string $run_mode = null,
        public readonly ?string $description = null,
        public readonly ?string $duration = null,
        public readonly ?string $output_dir = null,
        public readonly ?string $user = null,
        public readonly ?string $provider_name = null,
        public readonly ?string $provider_type = null,
        public readonly ?string $resolved_model = null,
        public readonly bool $is_scheduled = false,
        public readonly ?int $quality_score = null,
    ) {}

    /**
     * Parse a composite id back into [sourceKey, localId].
     * Returns [null, null] if the id doesn't have the expected form.
     */
    public static function parseId(string $composite): array
    {
        $pos = strpos($composite, '.');
        if ($pos === false) {
            return [null, null];
        }
        return [substr($composite, 0, $pos), substr($composite, $pos + 1)];
    }

    public static function compose(string $sourceKey, string|int $localId): string
    {
        return $sourceKey . '.' . $localId;
    }
}
