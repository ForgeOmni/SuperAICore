<?php

namespace SuperAICore\Console;

use SuperAICore\Console\Commands\AgentListCommand;
use SuperAICore\Console\Commands\AgentRunCommand;
use SuperAICore\Console\Commands\CallCommand;
use SuperAICore\Console\Commands\GeminiSyncCommand;
use SuperAICore\Console\Commands\ListBackendsCommand;
use SuperAICore\Console\Commands\SkillListCommand;
use SuperAICore\Console\Commands\SkillRunCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Standalone Symfony Console application for `bin/superaicore`.
 * Same commands are also registered as Laravel Artisan commands
 * via SuperAICoreServiceProvider when running inside a Laravel host.
 */
class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('superaicore', '0.1.0');

        $this->add(new CallCommand());
        $this->add(new ListBackendsCommand());
        $this->add(new SkillListCommand());
        $this->add(new SkillRunCommand());
        $this->add(new AgentListCommand());
        $this->add(new AgentRunCommand());
        $this->add(new GeminiSyncCommand());
    }
}
