<?php

declare(strict_types=1);

namespace Sinergia\Cli;

use Sinergia\Cli\Command\AppKeyGenerateCommand;
use Sinergia\Cli\Command\BotWorkerCommand;
use Sinergia\Cli\Command\CategoryCheckCommand;
use Sinergia\Cli\Command\HighlightsCheckCommand;
use Sinergia\Cli\Command\InstallationEnsureCommand;
use Sinergia\Cli\Command\MigrateCommand;
use Sinergia\Cli\Command\OAuthFinishCommand;
use Sinergia\Cli\Command\OAuthRefreshCommand;
use Sinergia\Cli\Command\OAuthStartCommand;
use Sinergia\Cli\Command\OAuthStatusCommand;
use Sinergia\Cli\Command\OffersSelectCommand;
use Sinergia\Cli\Command\UserCreateCommand;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Symfony\Component\Console\Application;

final class ConsoleApplication
{
    public static function create(Config $config, string $projectRoot): Application
    {
        $container = Kernel::container($config, $projectRoot);
        $app = new Application('SINERGIA BOT ML', $config->appVersion());
        $app->addCommands([
            new AppKeyGenerateCommand(),
            new MigrateCommand($container),
            new InstallationEnsureCommand($container),
            new UserCreateCommand($container),
            new OAuthStartCommand($container),
            new OAuthFinishCommand($container),
            new OAuthStatusCommand($container),
            new OAuthRefreshCommand($container),
            new CategoryCheckCommand($container),
            new HighlightsCheckCommand($container),
            new OffersSelectCommand($container),
            new BotWorkerCommand($container),
        ]);

        return $app;
    }
}
