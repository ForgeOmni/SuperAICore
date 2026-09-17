<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Feature;

use Illuminate\Support\Facades\Route;
use SuperAICore\Tests\TestCase;

/**
 * What of this package's 81 routes a host actually registers (1.2.0).
 *
 * The default middleware is `['web', 'auth']`, which in a product means every
 * signed-in account — including people who should never see a provider
 * registry, a cost dashboard or an OpenAI-compatible proxy that dispatches on
 * the host's credentials.
 */
class RouteSurfaceTest extends TestCase
{
    public function test_by_default_every_group_registers_exactly_as_before(): void
    {
        $uris = $this->packageRoutes();

        $this->assertNotEmpty($uris);
        $this->assertContains('super-ai-core/providers', $uris);
        $this->assertContains('super-ai-core/v1/chat/completions', $uris);
    }

    public function test_a_group_can_be_dropped_without_dropping_the_rest(): void
    {
        $this->refreshApplicationWith([
            'super-ai-core.route.groups' => ['openai_proxy' => false, 'pty' => false],
        ]);

        $uris = $this->packageRoutes();

        $this->assertContains('super-ai-core/providers', $uris, 'the rest of the surface stays');
        $this->assertNotContains('super-ai-core/v1/chat/completions', $uris);
        $this->assertEmpty(
            array_filter($uris, fn (string $uri) => str_starts_with($uri, 'super-ai-core/pty')),
            'no PTY endpoint should be reachable'
        );
    }

    public function test_the_gate_is_appended_to_the_package_middleware(): void
    {
        $this->refreshApplicationWith(['super-ai-core.route.gate' => 'manage-ai-core']);

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'super-ai-core/providers');

        $this->assertNotNull($route);
        $this->assertContains('can:manage-ai-core', $route->middleware());
        $this->assertContains('auth', $route->middleware(), 'the host stack is kept, not replaced');
    }

    public function test_an_embedded_host_registers_nothing(): void
    {
        putenv('AI_CORE_PROFILE=embedded');

        try {
            $this->refreshApplicationWith([]);
            $this->assertSame([], $this->packageRoutes());
        } finally {
            putenv('AI_CORE_PROFILE');
        }
    }

    /** @return list<string> */
    private function packageRoutes(): array
    {
        return array_values(array_filter(
            array_map(fn ($r) => $r->uri(), Route::getRoutes()->getRoutes()),
            fn (string $uri) => str_starts_with($uri, 'super-ai-core')
        ));
    }

    /** @param array<string,mixed> $config */
    private function refreshApplicationWith(array $config): void
    {
        $this->app['config']->set('super-ai-core.route.groups', []);
        $this->app['config']->set('super-ai-core.route.gate', null);

        foreach ($config as $key => $value) {
            $this->app['config']->set($key, $value);
        }

        // Re-register the provider so the route file is evaluated again with
        // the config this test set.
        Route::setRoutes(new \Illuminate\Routing\RouteCollection());
        (new \SuperAICore\SuperAICoreServiceProvider($this->app))->boot();
    }
}
