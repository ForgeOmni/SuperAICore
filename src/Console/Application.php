<?php

namespace SuperAICore\Console;

use SuperAICore\Console\Commands\AgentListCommand;
use SuperAICore\Console\Commands\AgentRunCommand;
use SuperAICore\Console\Commands\AutoCommand;
use SuperAICore\Console\Commands\ApiStatusCommand;
use SuperAICore\Console\Commands\CallCommand;
use SuperAICore\Console\Commands\ClaudeMcpSyncCommand;
use SuperAICore\Console\Commands\CliInstallCommand;
use SuperAICore\Console\Commands\CliStatusCommand;
use SuperAICore\Console\Commands\CopilotFleetCommand;
use SuperAICore\Console\Commands\CopilotSyncCommand;
use SuperAICore\Console\Commands\CopilotSyncHooksCommand;
use SuperAICore\Console\Commands\FallbackPolicyCommand;
use SuperAICore\Console\Commands\FlowCommand;
use SuperAICore\Console\Commands\GeminiSyncCommand;
use SuperAICore\Console\Commands\HooksSyncCommand;
use SuperAICore\Console\Commands\KimiSyncCommand;
use SuperAICore\Console\Commands\KiroSyncCommand;
use SuperAICore\Console\Commands\McpSyncBackendsCommand;
use SuperAICore\Console\Commands\ListBackendsCommand;
use SuperAICore\Console\Commands\ModelsCommand;
use SuperAICore\Console\Commands\PluginsInstallCommand;
use SuperAICore\Console\Commands\ProviderAddCommand;
use SuperAICore\Console\Commands\ProviderRotateCommand;
use SuperAICore\Console\Commands\SkillListCommand;
use SuperAICore\Console\Commands\SkillRunCommand;
use SuperAICore\Console\Commands\SmartCommand;
use SuperAICore\Console\Commands\SquadCommand;
use SuperAICore\Console\Commands\TeamCommand;
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
        parent::__construct('superaicore', '1.0.5');

        $this->add(new CallCommand());
        $this->add(new ListBackendsCommand());
        $this->add(new SkillListCommand());
        $this->add(new SkillRunCommand());
        $this->add(new AgentListCommand());
        $this->add(new AgentRunCommand());
        $this->add(new GeminiSyncCommand());
        $this->add(new CopilotSyncCommand());
        $this->add(new CopilotFleetCommand());
        $this->add(new CopilotSyncHooksCommand());
        $this->add(new HooksSyncCommand());
        $this->add(new KiroSyncCommand());
        $this->add(new KimiSyncCommand());
        $this->add(new ClaudeMcpSyncCommand());
        $this->add(new McpSyncBackendsCommand());
        $this->add(new CliStatusCommand());
        $this->add(new CliInstallCommand());
        $this->add(new ApiStatusCommand());
        $this->add(new ModelsCommand());
        $this->add(new ProviderAddCommand());
        $this->add(new ProviderRotateCommand());
        $this->add(new FallbackPolicyCommand());
        $this->add(new PluginsInstallCommand());
        $this->add(new SmartCommand());
        $this->add(new SquadCommand());
        $this->add(new AutoCommand());
        $this->add(new TeamCommand());
        // SmartFlow — cross-CLI dynamic workflows (the multi-CLI port of
        // Claude Code's built-in Workflow engine).
        $this->add(new FlowCommand());
    }
}
