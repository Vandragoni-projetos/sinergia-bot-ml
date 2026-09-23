<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Infrastructure\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:migrate', description: 'Aplica as migrations pendentes (idempotente).')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Migrator $migrator */
        $migrator = $this->container->get(Migrator::class);
        $applied = $migrator->migrate();
        $output->writeln($applied === [] ? 'Nada a aplicar: schema atualizado.' : 'Aplicadas: ' . implode(', ', $applied));

        return Command::SUCCESS;
    }
}
