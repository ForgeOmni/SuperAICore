<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Contracts\ProviderRepository;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\ProviderResolver;
use SuperAICore\Support\Profile;
use SuperAICore\Support\QuotaDecision;
use SuperAICore\Support\RouteGroups;
use SuperAICore\Support\RuntimeState;

/**
 * What a multi-tenant host needs from this package (1.2.0): a scope chain it
 * defines, usage rows it can bill from, a spend gate, a web surface it can
 * narrow, and no state carried from one tenant's job into the next.
 */
class MultiTenantSurfaceTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('AI_CORE_PROFILE');
        parent::tearDown();
    }

    // ── C1: scope chain ──────────────────────────────────────────────

    public function test_the_chain_stops_at_the_first_scope_with_a_provider(): void
    {
        $repo = $this->repo([
            'business:42' => ['id' => 7, 'name' => 'tenant key'],
            'global:0' => ['id' => 1, 'name' => 'platform key'],
        ]);

        $resolved = (new ProviderResolver($repo))->resolveChain([
            ['business', 42],
            ['user', 7],
            ['global', null],
        ]);

        $this->assertSame(7, $resolved['id'], "the tenant's own key wins over the platform's");
    }

    public function test_the_chain_falls_through_to_the_next_scope(): void
    {
        $repo = $this->repo(['global:0' => ['id' => 1]]);

        $resolved = (new ProviderResolver($repo))->resolveChain([
            ['business', 42],
            ['global', null],
        ]);

        $this->assertSame(1, $resolved['id']);
    }

    public function test_an_empty_chain_resolves_nothing_rather_than_guessing(): void
    {
        // Answering with the platform's credentials here would be spending
        // someone else's money on a caller that named no scope at all.
        $repo = $this->repo(['global:0' => ['id' => 1]]);

        $this->assertNull((new ProviderResolver($repo))->resolveChain([]));
    }

    public function test_resolve_for_user_still_means_user_then_global(): void
    {
        $repo = $this->repo(['global:0' => ['id' => 1]]);
        $resolver = new ProviderResolver($repo);

        $this->assertSame(1, $resolver->resolveForUser(7)['id']);
        $this->assertSame(
            [['user', 7, null], ['global', null, null]],
            $repo->calls,
            'the historical chain is unchanged'
        );
    }

    public function test_dispatch_options_carry_either_shape(): void
    {
        $this->assertSame(
            [['global', null]],
            Dispatcher::scopeChain([])
        );
        $this->assertSame(
            [['user', 7]],
            Dispatcher::scopeChain(['scope' => 'user', 'scope_id' => 7])
        );
        $this->assertSame(
            [['business', 42], ['global', null]],
            Dispatcher::scopeChain(['scopes' => [['business', 42], 'global']])
        );
    }

    // ── C2: usage attribution ────────────────────────────────────────

    public function test_a_usage_row_is_attributed_to_the_head_of_the_chain(): void
    {
        $this->assertSame(
            ['business', 42],
            Dispatcher::resolvedScope(['scopes' => [['business', 42], ['global', null]]])
        );
        $this->assertSame(
            ['user', 7],
            Dispatcher::resolvedScope(['scope' => 'user', 'scope_id' => 7])
        );
        $this->assertSame(
            ['global', null],
            Dispatcher::resolvedScope([]),
            'global is not keyed by an id'
        );
    }

    // ── C3: quota ────────────────────────────────────────────────────

    public function test_a_denial_carries_something_a_host_can_show_a_person(): void
    {
        $denial = QuotaDecision::deny('Daily cap of $5 reached.', 'daily_cap', ['reset_at' => 'midnight'])->toArray();

        $this->assertTrue($denial['quota_denied']);
        $this->assertSame('daily_cap', $denial['error_code']);
        $this->assertStringContainsString('Daily cap', $denial['error']);
        $this->assertSame(['reset_at' => 'midnight'], $denial['meta']);
    }

    public function test_an_allowance_is_not_a_refusal(): void
    {
        $this->assertTrue(QuotaDecision::allow()->allowed);
        $this->assertFalse(QuotaDecision::allow()->denied());
    }

    // ── C4 / C5 / C6: profile and route groups ───────────────────────

    public function test_workstation_is_the_default_and_keeps_every_capability(): void
    {
        putenv('AI_CORE_PROFILE');

        $this->assertSame(Profile::WORKSTATION, Profile::current());

        foreach (Profile::CAPABILITIES as $capability) {
            $this->assertTrue(Profile::allows($capability), "{$capability} should stay on by default");
        }
    }

    public function test_embedded_turns_off_everything_a_web_node_should_not_do(): void
    {
        putenv('AI_CORE_PROFILE=embedded');

        $this->assertSame(Profile::EMBEDDED, Profile::current());

        foreach (Profile::CAPABILITIES as $capability) {
            $this->assertFalse(Profile::allows($capability), "{$capability} should be off under embedded");
        }
    }

    public function test_the_config_file_reads_the_profile_for_the_keys_that_depend_on_it(): void
    {
        putenv('AI_CORE_PROFILE=embedded');
        $embedded = require __DIR__ . '/../../config/super-ai-core.php';

        putenv('AI_CORE_PROFILE=workstation');
        $workstation = require __DIR__ . '/../../config/super-ai-core.php';

        $this->assertFalse($embedded['route']['enabled']);
        $this->assertTrue($workstation['route']['enabled']);

        foreach (array_keys($embedded['backends']) as $backend) {
            if (! str_ends_with($backend, '_cli')) {
                continue;
            }
            $this->assertFalse($embedded['backends'][$backend]['enabled'], "{$backend} spawns a process");
            $this->assertTrue($workstation['backends'][$backend]['enabled']);
        }

        $this->assertFalse($embedded['snapshot']['enabled'], 'no writes to a working copy');
    }

    public function test_every_route_group_is_named_and_switchable(): void
    {
        putenv('AI_CORE_PROFILE=workstation');

        foreach (array_keys(RouteGroups::GROUPS) as $group) {
            $this->assertTrue(RouteGroups::enabled($group), "{$group} follows the profile when unset");
        }

        putenv('AI_CORE_PROFILE=embedded');
        $this->assertFalse(RouteGroups::enabled('openai_proxy'));
        $this->assertFalse(RouteGroups::enabled('pty'));
    }

    public function test_an_unknown_group_is_a_mistake_not_a_silent_false(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RouteGroups::enabled('no-such-group');
    }

    // ── C7: nothing carried between tenants ──────────────────────────

    public function test_the_inventory_names_classes_that_exist(): void
    {
        $inventory = RuntimeState::inventory();

        $this->assertNotEmpty($inventory['cleared']);
        $this->assertNotEmpty($inventory['kept']);

        foreach ($inventory['cleared'] as $entry) {
            [$class] = explode('::', $entry);
            $this->assertTrue(class_exists($class), "{$class} is named as cleared but does not exist");
        }

        foreach (array_keys($inventory['kept']) as $class) {
            $this->assertTrue(class_exists($class), "{$class} is named as kept but does not exist");
        }
    }

    public function test_a_provider_cooldown_does_not_outlive_the_tenant_that_caused_it(): void
    {
        $cooldowns = new \ReflectionProperty(\SuperAICore\Runner\TaskRunner::class, 'cooldowns');
        $cooldowns->setValue(null, ['anthropic_api' => time() + 600]);

        $this->assertNotSame([], $cooldowns->getValue());

        RuntimeState::resetPerTenant();

        $this->assertSame([], $cooldowns->getValue(), "one tenant's bad key must not sideline a provider for the next");
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @param array<string,array> $rows keyed "scope:scopeId" */
    private function repo(array $rows): ProviderRepository
    {
        return new class($rows) implements ProviderRepository {
            public array $calls = [];

            public function __construct(private array $rows)
            {
            }

            public function findActive(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): ?array
            {
                $this->calls[] = [$scope, $scopeId, $backend];

                return $this->rows[$scope . ':' . (int) $scopeId] ?? null;
            }

            public function listForScope(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): array
            {
                return [];
            }

            public function findById(int $id): ?array
            {
                return null;
            }

            public function create(array $data): int
            {
                return 0;
            }

            public function update(int $id, array $data): bool
            {
                return true;
            }

            public function delete(int $id): bool
            {
                return true;
            }

            public function activate(int $id): bool
            {
                return true;
            }

            public function deactivate(int $id): bool
            {
                return true;
            }
        };
    }
}
