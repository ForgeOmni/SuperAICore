<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Support\SuperAgentDetector;
use SuperAICore\Tests\TestCase;

class SuperAgentDetectorTest extends TestCase
{
    public function test_availability_matches_sdk_autoload_state(): void
    {
        // The CI matrix runs with and without forgeomni/superagent installed
        // (via a composer --prefer-lowest vs prefer-stable job). We don't
        // assert a fixed true/false — we assert the detector's return value
        // agrees with class_exists on the SuperAgent entry class.
        $expected = class_exists(\SuperAgent\Agent::class);
        $this->assertSame($expected, SuperAgentDetector::isAvailable());
    }
}
