<?php
// Simulate SDK-missing parse path: load composer autoload but block any
// SDK class resolution; confirm SuperAgentBackendTest.php parses cleanly.

require_once __DIR__ . '/vendor/autoload.php';

// Insert a SDK blocker as the FIRST autoloader (prepend=true)
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'SuperAgent\\')) {
        throw new \Error('SDK class blocked at autoload: ' . $class);
    }
    // Return without action; let other autoloaders try
    return;
}, true, true);

try {
    require_once __DIR__ . '/tests/Unit/SuperAgentBackendTest.php';
    echo "PARSE OK\n";
} catch (\Throwable $e) {
    echo 'PARSE FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
