<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Shared\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'installation:ensure-default', description: 'Cria (se não existir) a instalação local padrão.')]
final class InstallationEnsureCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nome exibido', 'Instalação local');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Config $config */
        $config = $this->container->get(Config::class);
        /** @var InstallationRepository $repo */
        $repo = $this->container->get(InstallationRepository::class);
        $installation = $repo->ensure($config->installationSlug(), (string) $input->getOption('name'), $config->siteId());
        $output->writeln(sprintf(
            'Instalação #%d "%s" (slug=%s, site=%s, status=%s).',
            $installation->id->value,
            $installation->name,
            $installation->slug,
            $installation->siteId,
            $installation->status,
        ));

        return Command::SUCCESS;
    }
}
