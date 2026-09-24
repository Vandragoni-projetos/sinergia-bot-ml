<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Integration\MercadoLivre\Exception\MercadoLivreException;
use Sinergia\Integration\MercadoLivre\OAuth\StoredTokenProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renovação controlada do access token pelo mesmo caminho da renovação automática
 * (StoredTokenProvider: lock, dupla verificação, gravação cifrada). Nunca exibe tokens.
 */
#[AsCommand(
    name: 'ml:oauth:refresh',
    description: 'Renova o access token do Mercado Livre se estiver perto de expirar (--force renova agora).',
)]
final class OAuthRefreshCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Renova mesmo que o access token ainda esteja válido.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Installation $installation */
        $installation = $this->container->get(Installation::class);

        try {
            $refreshed = $this->container->get(StoredTokenProvider::class)->refresh((bool) $input->getOption('force'));
        } catch (MercadoLivreException $e) {
            $output->writeln(sprintf('<error>Renovação não realizada (%s): %s</error>', $e->errorCode, $e->getMessage()));
            $this->printStatus($installation, $output);

            return Command::FAILURE;
        }

        $output->writeln($refreshed ? 'Token renovado.' : 'Renovação não necessária: access token ainda válido.');
        $this->printStatus($installation, $output);

        return Command::SUCCESS;
    }

    private function printStatus(Installation $installation, OutputInterface $output): void
    {
        $credential = $this->container->get(MlCredentialRepository::class)->find($installation->id);
        if ($credential === null) {
            $output->writeln('Não conectado. Rode: php bin/console ml:oauth:start');

            return;
        }

        $output->writeln(sprintf(
            "status=%s\naccess_expira_em=%s UTC\nrefresh_token=%s",
            $credential->status,
            $credential->accessExpiresAt->format('Y-m-d H:i:s'),
            $credential->refreshToken === null ? 'ausente' : 'presente (cifrado)',
        ));
    }
}
