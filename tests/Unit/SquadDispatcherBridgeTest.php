<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Unit;

use SuperAgent\Squad\SquadDispatcherRegistry;
use SuperAICore\Modes\CrossLayerDispatcher;
use SuperAICore\Modes\SquadDispatcherBridge;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * Reverse bridge: SDK 1.0.0+ `SquadDispatcherRegistry` SPI lets the
 * host install a default squad dispatcher. SuperAICore implements
 * that SPI so SDK-internal squad runs can route to CLI backends.
 *
 * Tests pin the loose-coupling contract: install() registers,
 * uninstall() unregisters, and the bridge silently degrades when the
 * SDK class isn't present (host vendor-pinned to a pre-SPI release).
 */
class SquadDispatcherBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(SquadDispatcherRegistry::class)) {
            $this->markTestSkipped('forgeomni/superagent not installed');
        }
        SquadDispatcherRegistry::clear();
    }

    protected function tearDown(): void
    {
        if (class_exists(SquadDispatcherRegistry::class)) {
            SquadDispatcherRegistry::clear();
        }
        parent::tearDown();
    }

    public function test_install_registers_dispatcher_with_sdk_registry(): void
    {
        $bridge = $this->makeBridge();
        $this->assertFalse(SquadDispatcherRegistry::has(), 'precondition: registry empty');

        $bridge->install();

        $this->assertTrue(SquadDispatcherRegistry::has());
        $this->assertIsCallable(SquadDispatcherRegistry::get());
    }

    public function test_uninstall_drops_registration(): void
    {
        $bridge = $this->makeBridge();
        $bridge->install();
        $this->assertTrue(SquadDispatcherRegistry::has());

        $bridge->uninstall();

        $this->assertFalse(SquadDispatcherRegistry::has());
        $this->assertNull(SquadDispatcherRegistry::get());
    }

    public function test_install_is_idempotent(): void
    {
        $bridge = $this->makeBridge();
        $bridge->install();
        $bridge->install();
        $bridge->install();
        $this->assertTrue(SquadDispatcherRegistry::has());
    }

    public function test_is_available_reflects_sdk_class_presence(): void
    {
        $bridge = $this->makeBridge();
        // SDK is a hard composer dep — registry class is always present
        // in this test matrix. The assertion locks in that the bridge
        // doesn't return false-negative when the SDK is available.
        $this->assertTrue($bridge->isAvailable());
    }

    private function makeBridge(): SquadDispatcherBridge
    {
        $core = new class extends Dispatcher {
            public function __construct() {}
            public function dispatch(array $options): ?array
            {
                return ['text' => '', 'cost_usd' => 0.0];
            }
        };
        $cross = new CrossLayerDispatcher($core);
        return new SquadDispatcherBridge($cross);
    }
}
