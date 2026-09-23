<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Shared\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ml:oauth:status', description: 'Mostra o estado da conexão OAuth (sem exibir tokens).')]
final class OAuthStatusCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Installation $installation */
        $installation = $this->container->get(Installation::class);
        $credential = $this->container->get(MlCredentialRepository::class)->find($installation->id);
        if ($credential === null) {
            $output->writeln('Não conectado. Rode: php bin/console ml:oauth:start');

            return Command::SUCCESS;
        }
        $now = $this->container->get(Clock::class)->now();
        $output->writeln(sprintf(
            "status=%s\nml_user_id=%s\nescopos=%s\naccess_expira_em=%s UTC (%s)\nrefresh_token=%s\nultima_chamada=%s",
            $credential->status,
            $credential->mlUserId === null ? '?' : (string) $credential->mlUserId,
            $credential->scopes ?? '?',
            $credential->accessExpiresAt->format('Y-m-d H:i:s'),
            $credential->accessValidFor($now, 0) ? 'válido' : 'expirado',
            $credential->refreshToken === null ? 'ausente' : 'presente (cifrado)',
            $credential->lastApiCallAt?->format('Y-m-d H:i:s') ?? '-',
        ));

        return Command::SUCCESS;
    }
}
