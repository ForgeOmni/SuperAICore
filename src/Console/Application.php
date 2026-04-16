<?php

namespace SuperAICore\Console;

use SuperAICore\Console\Commands\CallCommand;
use SuperAICore\Console\Commands\ListBackendsCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Standalone Symfony Console application for `bin/super-ai-core`.
 * Same commands are also registered as Laravel Artisan commands
 * via SuperAICoreServiceProvider when running inside a Laravel host.
 */
class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('super-ai-core', '0.1.0');

        $this->add(new CallCommand());
        $this->add(new ListBackendsCommand());
    }
}
