<?php

namespace SuperAICore\Tests\Support;

use SuperAICore\Services\KiroModelResolver;

/**
 * Pins KiroModelResolver to its static fallback for the duration of a test.
 *
 * The resolver's catalog() consults, in order: an in-process memo, the
 * on-disk cache under $HOME, and a live `kiro-cli` probe. On a developer
 * machine with kiro-cli installed, unit tests would otherwise assert
 * against whatever models that machine's Kiro account happens to expose —
 * results drift with subscription tier and upstream catalog changes.
 *
 * Call isolateKiroCatalog() in setUp() and restoreKiroCatalog() in
 * tearDown(). HOME is pointed at an empty temp dir (no cache file) and
 * KIRO_CLI_BIN at a nonexistent binary (probe fails), so catalog()
 * deterministically returns KiroModelResolver::STATIC_FALLBACK.
 */
trait IsolatesKiroCatalog
{
    private string|false $previousHome = false;
    private string|false $previousKiroBin = false;

    protected function isolateKiroCatalog(): void
    {
        $this->previousHome = getenv('HOME');
        $this->previousKiroBin = getenv('KIRO_CLI_BIN');

        $sandbox = sys_get_temp_dir() . '/superaicore-kiro-test-' . getmypid();
        if (!is_dir($sandbox)) {
            mkdir($sandbox, 0755, true);
        }
        putenv('HOME=' . $sandbox);
        putenv('KIRO_CLI_BIN=/nonexistent/kiro-cli-test-stub');

        KiroModelResolver::resetMemo();
    }

    protected function restoreKiroCatalog(): void
    {
        putenv($this->previousHome === false ? 'HOME' : 'HOME=' . $this->previousHome);
        putenv($this->previousKiroBin === false ? 'KIRO_CLI_BIN' : 'KIRO_CLI_BIN=' . $this->previousKiroBin);

        KiroModelResolver::resetMemo();
    }
}
