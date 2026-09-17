<?php

namespace SuperAICore\Console;

use SuperAICore\Console\Commands\AgentListCommand;
use SuperAICore\Console\Commands\AgentRunCommand;
use SuperAICore\Console\Commands\AliasesCommand;
use SuperAICore\Console\Commands\AutoCommand;
use SuperAICore\Console\Commands\ApiStatusCommand;
use SuperAICore\Console\Commands\CallCommand;
use SuperAICore\Console\Commands\DoctorCommand;
use SuperAICore\Console\Commands\InstallDispatchSkillCommand;
use SuperAICore\Console\Commands\PreferencesCommand;
use SuperAICore\Console\Commands\ResumeCommand;
use SuperAICore\Console\Commands\RunsCommand;
use SuperAICore\Console\Commands\SendCommand;
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
use Symfony\Component\Console\Command\Command;

/**
 * Standalone Symfony Console application for `bin/superaicore`.
 * Same commands are also registered as Laravel Artisan commands
 * via SuperAICoreServiceProvider when running inside a Laravel host.
 */
class Application extends SymfonyApplication
{
    /**
     * Register a command across Symfony Console majors.
     *
     * `Application::add()` was deprecated in Symfony Console 7.4 and removed
     * in 8.0, in favour of `addCommand()`. This package supports ^6, ^7 and
     * ^8 at once, so it cannot simply pick one.
     *
     * @since 1.2.0
     */
    private function register(Command $command): void
    {
        if (method_exists($this, 'addCommand')) {
            $this->addCommand($command);

            return;
        }

        $this->add($command);
    }

    public function __construct()
    {
        parent::__construct('superaicore', '1.2.0');

        $this->register(new CallCommand());
        $this->register(new ListBackendsCommand());
        $this->register(new SkillListCommand());
        $this->register(new SkillRunCommand());
        $this->register(new AgentListCommand());
        $this->register(new AgentRunCommand());
        $this->register(new GeminiSyncCommand());
        $this->register(new CopilotSyncCommand());
        $this->register(new CopilotFleetCommand());
        $this->register(new CopilotSyncHooksCommand());
        $this->register(new HooksSyncCommand());
        $this->register(new KiroSyncCommand());
        $this->register(new KimiSyncCommand());
        $this->register(new ClaudeMcpSyncCommand());
        $this->register(new McpSyncBackendsCommand());
        $this->register(new CliStatusCommand());
        $this->register(new CliInstallCommand());
        $this->register(new ApiStatusCommand());
        $this->register(new ModelsCommand());
        $this->register(new ProviderAddCommand());
        $this->register(new ProviderRotateCommand());
        $this->register(new FallbackPolicyCommand());
        $this->register(new PluginsInstallCommand());
        $this->register(new SmartCommand());
        $this->register(new SquadCommand());
        $this->register(new AutoCommand());
        $this->register(new TeamCommand());
        // SmartFlow — cross-CLI dynamic workflows (the multi-CLI port of
        // Claude Code's built-in Workflow engine).
        $this->register(new FlowCommand());
        // ai-dispatch parity wave — short-name send with route trace,
        // session resume, run archive, agent preferences, and doctor.
        $this->register(new SendCommand());
        $this->register(new ResumeCommand());
        $this->register(new RunsCommand());
        $this->register(new AliasesCommand());
        $this->register(new PreferencesCommand());
        $this->register(new DoctorCommand());
        $this->register(new InstallDispatchSkillCommand());
    }
}
