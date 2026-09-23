<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Persistence\OAuthStateRepository;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Integration\MercadoLivre\OAuth\Pkce;
use Sinergia\Shared\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ml:oauth:start',
    description: 'Gera a URL oficial de autorização do Mercado Livre (o login é feito por você no navegador).',
)]
final class OAuthStartCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var OAuthClient $oauth */
        $oauth = $this->container->get(OAuthClient::class);
        /** @var Installation $installation */
        $installation = $this->container->get(Installation::class);
        /** @var Clock $clock */
        $clock = $this->container->get(Clock::class);

        $state = Pkce::generateState();
        $verifier = $oauth->pkceEnabled() ? Pkce::generateVerifier() : null;
        $this->container->get(OAuthStateRepository::class)->create(
            $installation->id,
            $state,
            $verifier,
            $clock->now()->add(new \DateInterval('PT10M')),
        );

        $output->writeln('1) Abra esta URL no navegador, entre na SUA conta do Mercado Livre e autorize a aplicação:');
        $output->writeln('');
        $output->writeln($oauth->authorizationUrl($state, $verifier));
        $output->writeln('');
        $output->writeln('2) Você será redirecionado para a redirect_uri cadastrada, com ?code=...&state=... na barra de endereço.');
        $output->writeln('3) Em até 10 minutos, rode: php bin/console ml:oauth:finish  (a URL será pedida sem eco no terminal).');

        return Command::SUCCESS;
    }
}
