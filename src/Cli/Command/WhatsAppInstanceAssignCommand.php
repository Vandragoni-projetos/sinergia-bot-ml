<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Application\Port\WhatsApp\ProviderInstance;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\WhatsAppConnectionRepository;
use Sinergia\Integration\WhatsApp\Evolution\EvolutionCredential;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\SensitiveValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Evolution API, opção C: atribui a UMA conta a instância que o administrador criou FORA do BotML (com a chave global
 * da Evolution, que o BotML nunca recebe). Grava só a credencial da própria instância ("evo1:<nome>:<token>"),
 * cifrada com a APP_KEY. O token é pedido sem eco e nunca é exibido. Não chama a Evolution: a primeira conexão pelo
 * painel valida a credencial.
 */
#[AsCommand(name: 'whatsapp:instance:assign', description: 'Atribui a uma conta a instância da Evolution API criada pelo administrador (token pedido sem eco, gravado cifrado).')]
final class WhatsAppInstanceAssignCommand extends Command
{
    /** @param (\Closure(InputInterface, OutputInterface): ?SensitiveValue)|null $tokenReader leitura do token (padrão: pergunta sem eco) */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?\Closure $tokenReader = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('installation', null, InputOption::VALUE_REQUIRED, 'Slug da conta (installation) que vai usar a instância')
            ->addOption('instance', null, InputOption::VALUE_REQUIRED, 'Nome da instância na Evolution (letras minúsculas, números e hífen)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->get(Config::class);
        if ($config->whatsAppProvider() !== Config::WHATSAPP_EVOLUTION) {
            $output->writeln('<error>Este servidor não usa a Evolution API (defina WHATSAPP_PROVIDER=evolution). Nada foi gravado.</error>');

            return Command::FAILURE;
        }
        $slug = trim((string) $input->getOption('installation'));
        $name = trim((string) $input->getOption('instance'));
        if ($slug === '') {
            $output->writeln('<error>Informe --installation com o slug da conta.</error>');

            return Command::INVALID;
        }
        if (!EvolutionCredential::isValidName($name)) {
            $output->writeln('<error>Informe --instance com o nome da instância: só letras minúsculas, números e hífen (3 a 64).</error>');

            return Command::INVALID;
        }
        $installation = $this->container->get(InstallationRepository::class)->findBySlug($slug);
        if ($installation === null) {
            $output->writeln('<error>Conta não encontrada para esse slug.</error>');

            return Command::FAILURE;
        }

        $pdo = $this->container->get(\PDO::class);
        $other = $pdo->prepare('SELECT 1 FROM whatsapp_connections WHERE provider = :provider AND instance_name = :name AND installation_id <> :inst');
        $other->execute(['provider' => Config::WHATSAPP_EVOLUTION, 'name' => $name, 'inst' => $installation->id->value]);
        if ($other->fetchColumn() !== false) {
            $output->writeln('<error>Essa instância já está atribuída a outra conta. Cada conta precisa da própria instância. Nada foi gravado.</error>');

            return Command::FAILURE;
        }
        $current = $pdo->prepare('SELECT provider, status FROM whatsapp_connections WHERE installation_id = :inst');
        $current->execute(['inst' => $installation->id->value]);
        $row = $current->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row) && $row['provider'] !== Config::WHATSAPP_EVOLUTION) {
            $output->writeln('<error>Esta conta tem uma conexão de outro provedor de WhatsApp. Nada foi gravado.</error>');

            return Command::FAILURE;
        }
        if (is_array($row) && in_array($row['status'], ['connected', 'connecting'], true)) {
            $output->writeln('<error>O WhatsApp desta conta está conectado ou conectando. Desconecte pelo painel antes de trocar a instância. Nada foi gravado.</error>');

            return Command::FAILURE;
        }

        $token = ($this->tokenReader ?? $this->askToken(...))($input, $output);
        if ($token === null || $token->isEmpty()) {
            $output->writeln('<error>Token não informado (ou as duas digitações não conferem). Nada foi gravado.</error>');

            return Command::INVALID;
        }
        try {
            $credential = EvolutionCredential::compose($name, $token);
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>Token da instância em formato inválido. Nada foi gravado.</error>');

            return Command::INVALID;
        }

        $now = $this->container->get(Clock::class)->now();
        $connections = $this->container->get(WhatsAppConnectionRepository::class);
        $connections->ensure($installation->id, Config::WHATSAPP_EVOLUTION, $name, null, $now);
        $connections->storeInstance($installation->id, new ProviderInstance(null, $name, $credential), null, $now);

        $output->writeln(sprintf('Instância "%s" atribuída à conta "%s" (%s). Credencial gravada cifrada; o token não é exibido.', $name, $installation->name, $installation->slug));
        $output->writeln('Próximo passo: no painel, Conexões › WhatsApp › Conectar (QR code).');

        return Command::SUCCESS;
    }

    private function askToken(InputInterface $input, OutputInterface $output): ?SensitiveValue
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $first = new Question('Token da instância (não será exibido): ');
        $first->setHidden(true);
        $first->setHiddenFallback(false);
        $second = new Question('Repita o token: ');
        $second->setHidden(true);
        $second->setHiddenFallback(false);

        $token = new SensitiveValue(trim((string) $helper->ask($input, $output, $first)));
        $confirmation = new SensitiveValue(trim((string) $helper->ask($input, $output, $second)));

        return $token->equals($confirmation) ? $token : null;
    }
}
