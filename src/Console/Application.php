<?php

namespace ForgeOmni\AiCore\Console;

use ForgeOmni\AiCore\Console\Commands\CallCommand;
use ForgeOmni\AiCore\Console\Commands\ListBackendsCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Standalone Symfony Console application for `bin/ai-core`.
 * Same commands are also registered as Laravel Artisan commands
 * via AiCoreServiceProvider when running inside a Laravel host.
 */
class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('ai-core', '0.1.0');

        $this->add(new CallCommand());
        $this->add(new ListBackendsCommand());
    }
}
