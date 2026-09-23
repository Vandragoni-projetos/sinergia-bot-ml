<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Sinergia\Infrastructure\Crypto\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:key:generate', description: 'Gera uma APP_KEY nova (copie para o .env local; nunca versione).')]
final class AppKeyGenerateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('APP_KEY=' . SecretBox::generateKeyBase64());
        $output->writeln('<comment>Guarde este valor só no .env / secret do ambiente. Trocar a chave invalida tokens já cifrados.</comment>');

        return Command::SUCCESS;
    }
}
