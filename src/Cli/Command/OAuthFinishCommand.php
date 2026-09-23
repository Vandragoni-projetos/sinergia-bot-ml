<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Persistence\MlCredentialRepository;
use Sinergia\Infrastructure\Persistence\OAuthStateRepository;
use Sinergia\Integration\MercadoLivre\Exception\MercadoLivreException;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\SensitiveValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(
    name: 'ml:oauth:finish',
    description: 'Troca o code (da URL de retorno) por tokens e grava-os cifrados.',
)]
final class OAuthFinishCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new Question('Cole a URL completa para a qual o Mercado Livre redirecionou (não será exibida): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $callbackUrl = (string) $helper->ask($input, $output, $question);

        $query = [];
        parse_str((string) parse_url(trim($callbackUrl), PHP_URL_QUERY), $query);
        unset($callbackUrl);
        $code = is_string($query['code'] ?? null) ? new SensitiveValue($query['code']) : null;
        $state = is_string($query['state'] ?? null) ? new SensitiveValue($query['state']) : null;
        if ($code === null || $state === null) {
            $output->writeln('<error>URL sem "code" e "state". Nada foi gravado.</error>');

            return Command::FAILURE;
        }

        /** @var Installation $installation */
        $installation = $this->container->get(Installation::class);
        /** @var Clock $clock */
        $clock = $this->container->get(Clock::class);
        /** @var OAuthClient $oauth */
        $oauth = $this->container->get(OAuthClient::class);

        try {
            $pending = $this->container->get(OAuthStateRepository::class)->consume($installation->id, $state, $clock->now());
            $tokens = $oauth->exchangeCode($code, $pending['verifier']);
        } catch (MercadoLivreException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->container->get(MlCredentialRepository::class)->save($installation->id, $oauth->clientId(), $tokens, $clock->now());
        $output->writeln(sprintf(
            'Conectado. user_id=%s, escopos=%s, access expira em %d s. Tokens gravados cifrados (nunca exibidos).',
            $tokens->userId === null ? '?' : (string) $tokens->userId,
            $tokens->scope ?? '?',
            $tokens->expiresIn,
        ));

        return Command::SUCCESS;
    }
}
