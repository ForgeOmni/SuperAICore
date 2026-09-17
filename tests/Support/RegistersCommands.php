<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Support;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Register a command on a Symfony Console application, whichever major is
 * installed.
 *
 * `Application::add()` was deprecated in Console 7.4 and removed in 8.0 in
 * favour of `addCommand()`. This package's matrix spans ^6, ^7 and ^8.
 */
trait RegistersCommands
{
    protected function registerCommand(Application $app, Command $command): Command
    {
        if (method_exists($app, 'addCommand')) {
            $app->addCommand($command);
        } else {
            $app->add($command);
        }

        return $command;
    }
}
