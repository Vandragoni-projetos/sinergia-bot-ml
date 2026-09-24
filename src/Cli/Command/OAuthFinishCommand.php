<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Application\OAuth\CompleteMercadoLivreAuthorization;
use Sinergia\Application\OAuth\OAuthAuthorizationFailed;
use Sinergia\Application\OAuth\OAuthCallbackParameters;
use Sinergia\Domain\Installation\Installation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Alternativa de terminal ao callback web (GET /oauth/mercadolivre/callback):
 * ambos concluem o OAuth pelo mesmo caso de uso.
 */
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
        $params = OAuthCallbackParameters::fromCallbackUrl((string) $helper->ask($input, $output, $question));

        /** @var Installation $installation */
        $installation = $this->container->get(Installation::class);

        try {
            $tokens = $this->container->get(CompleteMercadoLivreAuthorization::class)->complete($installation->id, $params);
        } catch (OAuthAuthorizationFailed $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Conectado. user_id=%s, escopos=%s, access expira em %d s. Tokens gravados cifrados (nunca exibidos).',
            $tokens->userId === null ? '?' : (string) $tokens->userId,
            $tokens->scope ?? '?',
            $tokens->expiresIn,
        ));

        return Command::SUCCESS;
    }
}
