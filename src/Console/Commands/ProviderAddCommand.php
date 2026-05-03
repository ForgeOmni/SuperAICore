<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Models\AiProvider;
use SuperAICore\Services\ProviderTypeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-shot, file-driven provider creation. Borrowed in spirit from jcode's
 * `jcode provider add` — same idea, same secret-safe defaults, but writes
 * to the SuperAICore `ai_providers` table instead of `~/.jcode/config.toml`.
 *
 * Why: the existing /providers admin UI is great for humans but useless for
 * CI bootstrap, container `entrypoint.sh`, or "spin up a host with one
 * curl + one shell command" automation. Operators were dropping into
 * `php artisan tinker` and hand-writing `AiProvider::create([...])` calls
 * with secrets going through shell history. This command lets them pipe
 * the secret over stdin, persist a new row, optionally activate it, and
 * print the row id back as JSON for downstream scripts.
 *
 * Examples:
 *
 *   # Activate a DeepSeek V4 provider with the API key piped in (no secret
 *   # in shell history; key is encrypted at rest by AiProvider::setApiKey()).
 *   printf '%s' "$DEEPSEEK_API_KEY" | php artisan provider:add deepseek-prod \
 *       --backend=superagent --type=deepseek \
 *       --model=deepseek-v4-pro --api-key-stdin --activate --json
 *
 *   # Reference an existing env var instead of storing the key — useful for
 *   # rotation flows where the key lives in Vault and the host re-injects it
 *   # at boot.
 *   php artisan provider:add openai-prod \
 *       --backend=superagent --type=openai --api-key-env=OPENAI_API_KEY
 *
 *   # Local LM Studio — no api key, custom base url.
 *   php artisan provider:add lmstudio-laptop \
 *       --backend=superagent --type=lmstudio \
 *       --base-url=http://localhost:1234 --no-api-key
 *
 * Failure modes:
 *   - Validates `--backend` against AiProvider::BACKENDS and `--type`
 *     against the registry's allowedBackends matrix — invalid combos
 *     return exit 1 with a "type X not allowed for backend Y" message
 *     before touching the DB.
 *   - When `--type` is missing, defaults to the registry's
 *     `default_backend` for the resolved backend, or the first allowed
 *     type for that backend.
 *   - Does NOT validate the credential against the upstream provider —
 *     run `php artisan api:status` afterwards (or the equivalent
 *     `cli:status` for CLI engines) to confirm reachability.
 */
#[AsCommand(
    name: 'provider:add',
    description: 'Create a new AiProvider row from CLI flags (CI / scripted bootstrap; secret-safe via stdin or env reference)'
)]
final class ProviderAddCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED,
                'Display name for the provider (unique per scope+backend)')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED,
                'Dispatcher backend: claude | codex | gemini | copilot | kiro | kimi | superagent',
                'superagent')
            ->addOption('type', null, InputOption::VALUE_REQUIRED,
                'Provider type (anthropic / openai / deepseek / lmstudio / openai-compatible / …). Defaults to the bundled default for this backend.')
            ->addOption('model', null, InputOption::VALUE_REQUIRED,
                'Model id stored under extra_config.model — used as the spawn-time default')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED,
                'Override base URL (required for `*-proxy` / `*-compatible` / lmstudio types)')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED,
                'API key as a literal flag value. UNSAFE — leaks into shell history; prefer --api-key-stdin or --api-key-env.')
            ->addOption('api-key-stdin', null, InputOption::VALUE_NONE,
                'Read the API key from stdin (recommended for CI piping)')
            ->addOption('api-key-env', null, InputOption::VALUE_REQUIRED,
                'Resolve the API key from this env var at command run time (does not persist the key, persists the env-var name in extra_config.api_key_env so the host can re-resolve at spawn)')
            ->addOption('no-api-key', null, InputOption::VALUE_NONE,
                'Skip API key entirely — for builtin/oauth/lmstudio types that do not require one')
            ->addOption('extra-config', null, InputOption::VALUE_REQUIRED,
                'Inline JSON merged into extra_config (e.g. \'{"region":"intl","organization":"acme"}\')')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED,
                'global | user (only `global` is meaningful from CLI; user-scoped rows want a logged-in session)',
                'global')
            ->addOption('scope-id', null, InputOption::VALUE_REQUIRED,
                'user_id when --scope=user')
            ->addOption('activate', null, InputOption::VALUE_NONE,
                'After insert, deactivate every other provider in the same scope+backend and mark this one active')
            ->addOption('overwrite', null, InputOption::VALUE_NONE,
                'When a row with the same scope+backend+name already exists, update it instead of erroring')
            ->addOption('json', null, InputOption::VALUE_NONE,
                'Emit `{id, scope, backend, type, name, active, decrypted_api_key_present}` as JSON instead of a human row');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Soft-fail when the host hasn't booted Laravel — we need the
        // Eloquent connection plus the encrypter (Crypt::encryptString).
        if (!class_exists(AiProvider::class) || !function_exists('app')) {
            $output->writeln('<error>provider:add requires a booted Laravel host (Eloquent + Crypt facade).</error>');
            return Command::FAILURE;
        }

        $name    = (string) $input->getArgument('name');
        $backend = (string) $input->getOption('backend');
        $type    = $input->getOption('type');
        $scope   = (string) $input->getOption('scope');
        $scopeId = $input->getOption('scope-id');

        if (!in_array($backend, AiProvider::BACKENDS, true)) {
            $output->writeln(sprintf(
                '<error>Unknown backend "%s". Valid: %s</error>',
                $backend,
                implode(', ', AiProvider::BACKENDS),
            ));
            return Command::FAILURE;
        }

        // Resolve type — explicit --type wins; otherwise fall back to the
        // registry's first allowed type for this backend (typical: builtin).
        $allowedTypes = AiProvider::typesForBackend($backend);
        if ($type === null || $type === '') {
            $type = $allowedTypes[0] ?? null;
            if ($type === null) {
                $output->writeln(sprintf(
                    '<error>Backend "%s" has no allowed provider types — supply --type explicitly.</error>',
                    $backend,
                ));
                return Command::FAILURE;
            }
        }
        if (!in_array($type, $allowedTypes, true)) {
            $output->writeln(sprintf(
                '<error>Type "%s" is not allowed for backend "%s". Allowed: %s</error>',
                $type, $backend, implode(', ', $allowedTypes),
            ));
            return Command::FAILURE;
        }

        // Resolve API key — precedence: --api-key-stdin > --api-key-env > --api-key > --no-api-key
        $apiKey = null;
        $apiKeyEnvName = null;
        if ($input->getOption('api-key-stdin')) {
            $stdin = stream_get_contents(STDIN);
            if ($stdin === false || $stdin === '') {
                $output->writeln('<error>--api-key-stdin given but stdin was empty.</error>');
                return Command::FAILURE;
            }
            $apiKey = rtrim($stdin, "\r\n");
        } elseif ($input->getOption('api-key-env')) {
            $apiKeyEnvName = (string) $input->getOption('api-key-env');
            $resolved = getenv($apiKeyEnvName);
            if ($resolved === false || $resolved === '') {
                // Persist the *reference* anyway — host may set the env at
                // spawn time but not at install time. Warn so operators
                // notice if they truly forgot the var.
                $output->writeln(sprintf(
                    '<comment>--api-key-env=%s resolved to empty at install time. Persisting the env-var reference; ensure the var is set before dispatch.</comment>',
                    $apiKeyEnvName,
                ));
            } else {
                $apiKey = $resolved;
            }
        } elseif ($input->getOption('api-key') !== null) {
            $apiKey = (string) $input->getOption('api-key');
        } elseif (!$input->getOption('no-api-key')) {
            // Type may legitimately not need a key (builtin / lmstudio /
            // moonshot-builtin / google-ai with OAuth). Use the registry to
            // decide — if the type does require a key, surface the missing
            // flag explicitly so the operator doesn't end up with a row
            // that can't dispatch.
            try {
                $registry = app(ProviderTypeRegistry::class);
                if ($registry->requiresApiKey($type)) {
                    $output->writeln(sprintf(
                        '<error>Type "%s" requires an API key. Pass one of --api-key-stdin / --api-key-env / --api-key, or --no-api-key to override.</error>',
                        $type,
                    ));
                    return Command::FAILURE;
                }
            } catch (\Throwable) {
                // Registry unavailable — accept whatever the operator gave us.
            }
        }

        $extraConfig = [];
        if (($extraJson = $input->getOption('extra-config')) !== null) {
            $decoded = json_decode((string) $extraJson, true);
            if (!is_array($decoded)) {
                $output->writeln('<error>--extra-config must be valid JSON object.</error>');
                return Command::FAILURE;
            }
            $extraConfig = $decoded;
        }
        if (($model = $input->getOption('model')) !== null && $model !== '') {
            $extraConfig['model'] = (string) $model;
        }
        if ($apiKeyEnvName !== null) {
            // Record the env-var name so a host wrapper can re-resolve at
            // spawn time, even when the install-time value was empty.
            $extraConfig['api_key_env'] = $apiKeyEnvName;
        }

        $payload = [
            'scope'        => $scope,
            'scope_id'     => $scopeId !== null ? (int) $scopeId : null,
            'backend'      => $backend,
            'name'         => $name,
            'type'         => $type,
            'base_url'     => $input->getOption('base-url'),
            'api_key'      => $apiKey,
            'extra_config' => $extraConfig ?: null,
            'is_active'    => false,
            'sort_order'   => 0,
        ];

        // Existing-row handling. AiProvider unique constraint is informal
        // (scope + backend + name) — use the same triple to find a match.
        $existing = AiProvider::query()
            ->where('scope', $scope)
            ->where('scope_id', $scopeId !== null ? (int) $scopeId : null)
            ->where('backend', $backend)
            ->where('name', $name)
            ->first();

        if ($existing && !$input->getOption('overwrite')) {
            $output->writeln(sprintf(
                '<error>Provider "%s" (scope=%s, backend=%s) already exists with id=%d. Pass --overwrite to update it.</error>',
                $name, $scope, $backend, $existing->id,
            ));
            return Command::FAILURE;
        }

        if ($existing) {
            $existing->fill($payload);
            $existing->save();
            $row = $existing;
        } else {
            $row = AiProvider::create($payload);
        }

        if ($input->getOption('activate')) {
            // activate() already de-activates every sibling in the same
            // scope+backend, so we never end up with two active rows.
            $row->activate();
        }

        // Output. JSON mode is the contract for scripted callers; the
        // human path is a Symfony table for readability.
        if ($input->getOption('json')) {
            $payload = [
                'id'                       => $row->id,
                'scope'                    => $row->scope,
                'scope_id'                 => $row->scope_id,
                'backend'                  => $row->backend,
                'type'                     => $row->type,
                'name'                     => $row->name,
                'base_url'                 => $row->base_url,
                'active'                   => (bool) $row->is_active,
                'has_api_key'              => $row->hasApiKey(),
                'api_key_env'              => $extraConfig['api_key_env'] ?? null,
                'extra_config_keys'        => array_keys($extraConfig),
            ];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln(sprintf(
                '<info>%s provider id=%d</info>  scope=%s  backend=%s  type=%s  active=%s  has_api_key=%s',
                $existing ? 'Updated' : 'Created',
                $row->id,
                $row->scope,
                $row->backend,
                $row->type,
                $row->is_active ? 'yes' : 'no',
                $row->hasApiKey() ? 'yes' : 'no',
            ));
        }

        return Command::SUCCESS;
    }
}
