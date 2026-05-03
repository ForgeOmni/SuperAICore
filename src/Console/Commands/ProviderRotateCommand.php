<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Models\AiProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rotate the active provider for a given backend to the next candidate.
 * Borrowed in spirit from jcode's `/account` quick-switch — same problem
 * shape (ran out of tokens on account A; want to flip to account B in
 * one keystroke), bound here to the SuperAICore `ai_providers` table
 * and the existing per-backend single-active invariant.
 *
 * Selection order:
 *   1. Take the currently-active row for `<backend>` in `<scope>` (the
 *      one `getActiveForScope()` would return).
 *   2. Walk the candidate list ordered by `(sort_order ASC, id ASC)`,
 *      skipping any rows where `is_active = false` would not actually
 *      help (e.g. the same row, rows missing an api_key when the type
 *      requires one, rows the operator marked `extra_config.disabled = true`).
 *   3. Activate the first viable candidate (this also de-activates the
 *      old one — `activate()` flips siblings off in the same scope+backend).
 *   4. Stamp `extra_config.last_rotation_reason` + `last_rotation_at` on
 *      the freshly-activated row so dashboards can render "rotated 5m
 *      ago because: quota_exceeded".
 *
 * Examples:
 *
 *   # Quota fired on the active SuperAgent provider — rotate to the next.
 *   php artisan provider:rotate superagent --reason=quota_exceeded
 *
 *   # Force a specific provider as the next active row.
 *   php artisan provider:rotate claude --to=8 --reason=manual_swap --json
 *
 *   # Wrap-around: rotate even when the active row is already the last
 *   # candidate (default goes back to the first).
 *   php artisan provider:rotate codex --wrap
 *
 * Failure modes:
 *   - No active row → exits 1 with "no active provider" (use `provider:add
 *     --activate` to bootstrap one first).
 *   - Only one candidate → exits 1 unless `--allow-self` is given (wraps
 *     to itself, useful for refreshing the rotation timestamp).
 *   - Specified `--to` not found / wrong backend / wrong scope → exits 1.
 */
#[AsCommand(
    name: 'provider:rotate',
    description: 'Swap the active AiProvider for a backend to the next candidate (jcode-style /account swap, plus auto-rotation on quota errors)'
)]
final class ProviderRotateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('backend', InputArgument::REQUIRED,
                'Dispatcher backend whose active row should rotate (claude / codex / gemini / copilot / kiro / kimi / superagent)')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED,
                'global | user', 'global')
            ->addOption('scope-id', null, InputOption::VALUE_REQUIRED,
                'user_id when --scope=user')
            ->addOption('to', null, InputOption::VALUE_REQUIRED,
                'AiProvider id to activate explicitly (skips the rotation walk; still records --reason)')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED,
                'Why this rotation happened — written to extra_config.last_rotation_reason for the freshly-activated row',
                'manual')
            ->addOption('wrap', null, InputOption::VALUE_NONE,
                'Allow wrap-around when the current active row is the last candidate (default: refuse and exit 1)')
            ->addOption('allow-self', null, InputOption::VALUE_NONE,
                'Allow rotating to the currently-active provider — refreshes the rotation timestamp without changing the row')
            ->addOption('json', null, InputOption::VALUE_NONE,
                'Emit `{from_id, to_id, backend, scope, reason, rotated_at}` as JSON instead of a human line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists(AiProvider::class)) {
            $output->writeln('<error>provider:rotate requires a booted Laravel host (Eloquent).</error>');
            return Command::FAILURE;
        }

        $backend = (string) $input->getArgument('backend');
        if (!in_array($backend, AiProvider::BACKENDS, true)) {
            $output->writeln(sprintf('<error>Unknown backend "%s". Valid: %s</error>',
                $backend, implode(', ', AiProvider::BACKENDS),
            ));
            return Command::FAILURE;
        }

        $scope = (string) $input->getOption('scope');
        $scopeId = $input->getOption('scope-id');
        $scopeId = $scopeId !== null ? (int) $scopeId : null;
        $reason = (string) $input->getOption('reason');

        $current = AiProvider::getActiveForScope($scope, $scopeId, $backend);
        if (!$current) {
            $output->writeln(sprintf(
                '<error>No active provider for backend=%s in scope=%s. Bootstrap one with `provider:add ... --activate`.</error>',
                $backend, $scope,
            ));
            return Command::FAILURE;
        }

        // Resolve target.
        $targetId = $input->getOption('to');
        $next = null;
        if ($targetId !== null && $targetId !== '') {
            $next = AiProvider::query()
                ->where('id', (int) $targetId)
                ->where('scope', $scope)
                ->where('scope_id', $scopeId)
                ->where('backend', $backend)
                ->first();
            if (!$next) {
                $output->writeln(sprintf(
                    '<error>Provider id=%s not found for backend=%s scope=%s. Use `--scope-id` if user-scoped.</error>',
                    $targetId, $backend, $scope,
                ));
                return Command::FAILURE;
            }
        } else {
            $candidates = AiProvider::getForScope($scope, $scopeId, $backend);
            // Filter the list to the rows that actually look usable —
            // an api-key-required row with no key serves only to wedge
            // the dispatcher into immediate failure, so skip it.
            $usable = $candidates->filter(static function (AiProvider $row) use ($current): bool {
                if ($row->id === $current->id) return false;        // skip the one we're rotating off
                $extra = is_array($row->extra_config) ? $row->extra_config : [];
                if (!empty($extra['disabled'])) return false;        // explicit operator block
                return !$row->requiresApiKey() || $row->hasApiKey();
            })->values();

            if ($usable->isEmpty()) {
                if ($input->getOption('allow-self')) {
                    $next = $current; // refresh timestamp on the same row
                } else {
                    $output->writeln(sprintf(
                        '<error>Only one usable provider for backend=%s scope=%s. Pass --allow-self to refresh, or --wrap if you want to allow wrap-around (no other candidate exists either way).</error>',
                        $backend, $scope,
                    ));
                    return Command::FAILURE;
                }
            } else {
                // Walk by sort_order — first row that's > current's sort_order
                // wins; if none, wrap (when --wrap) or fall back to first.
                $currentOrder = (int) $current->sort_order;
                $next = $usable->first(static function (AiProvider $row) use ($currentOrder): bool {
                    return ((int) $row->sort_order) > $currentOrder;
                });
                if (!$next) {
                    if (!$input->getOption('wrap')) {
                        // Only error when there genuinely was no row past the
                        // current one — operator may not want to silently
                        // wrap to row #1, especially if rows further down
                        // are intentional fallbacks.
                        // Allow the implicit case where every usable row has
                        // the same sort_order as current — pick the first
                        // by id instead of erroring out.
                        $sameOrder = $usable->filter(static fn (AiProvider $r) => (int) $r->sort_order === $currentOrder);
                        if ($sameOrder->isEmpty()) {
                            $output->writeln(sprintf(
                                '<error>Active provider id=%d is already the last candidate (sort_order=%d). Pass --wrap to rotate back to the first.</error>',
                                $current->id, $currentOrder,
                            ));
                            return Command::FAILURE;
                        }
                        $next = $sameOrder->first();
                    } else {
                        $next = $usable->first();
                    }
                }
            }
        }

        if (!$next) {
            $output->writeln('<error>Could not resolve a target provider for rotation.</error>');
            return Command::FAILURE;
        }

        $fromId = $current->id;
        $extra = is_array($next->extra_config) ? $next->extra_config : [];
        $extra['last_rotation_reason'] = $reason;
        $extra['last_rotation_at'] = date('c');
        $extra['last_rotation_from'] = $fromId;
        $next->extra_config = $extra;
        $next->save();
        $next->activate(); // de-activates siblings in the same scope+backend

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'ok'         => true,
                'from_id'    => $fromId,
                'to_id'      => $next->id,
                'backend'    => $backend,
                'scope'      => $scope,
                'scope_id'   => $scopeId,
                'reason'     => $reason,
                'rotated_at' => $extra['last_rotation_at'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf(
                '<info>Rotated %s</info>: id=%d (%s) → id=%d (%s)  reason=%s',
                $backend,
                $fromId, $current->name,
                $next->id, $next->name,
                $reason,
            ));
        }

        return Command::SUCCESS;
    }
}
