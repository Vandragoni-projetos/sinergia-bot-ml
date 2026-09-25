<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Application\Auth\PasswordHasher;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\UserRepository;
use Sinergia\Shared\Config\SensitiveValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Cria um usuário (dono) numa conta existente, identificada pelo slug — nunca por ID fixo.
 * Senha: digitada sem eco (duas vezes) ou gerada com --generate-password (exibida uma única vez).
 */
#[AsCommand(name: 'user:create', description: 'Cria um usuário do painel numa conta (installation) existente.')]
final class UserCreateCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail de acesso')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nome exibido no painel')
            ->addOption('installation', null, InputOption::VALUE_REQUIRED, 'Slug da conta (installation) à qual o usuário pertence')
            ->addOption('generate-password', null, InputOption::VALUE_NONE, 'Gera uma senha forte e a exibe uma única vez');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = AuthService::normalizeEmail((string) $input->getArgument('email'));
        $name = trim((string) $input->getOption('name'));
        $slug = trim((string) $input->getOption('installation'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            $output->writeln('<error>E-mail inválido.</error>');

            return Command::INVALID;
        }
        if ($name === '' || mb_strlen($name) > 120) {
            $output->writeln('<error>Informe --name (até 120 caracteres).</error>');

            return Command::INVALID;
        }
        if ($slug === '') {
            $output->writeln('<error>Informe --installation com o slug da conta.</error>');

            return Command::INVALID;
        }

        $installation = $this->container->get(InstallationRepository::class)->findBySlug($slug);
        if ($installation === null) {
            $output->writeln('<error>Conta não encontrada para esse slug.</error>');

            return Command::FAILURE;
        }
        $users = $this->container->get(UserRepository::class);
        if ($users->findByEmail($email) !== null) {
            $output->writeln('<error>Já existe um usuário com esse e-mail.</error>');

            return Command::FAILURE;
        }

        $generated = (bool) $input->getOption('generate-password');
        $password = $generated ? PasswordHasher::generate() : $this->askPassword($input, $output);
        if ($password === null) {
            return Command::FAILURE;
        }

        $hasher = $this->container->get(PasswordHasher::class);
        $id = $users->create($installation->id, $email, $name, $hasher->hash($password));

        $output->writeln(sprintf('Usuário #%d criado na conta "%s" (%s).', $id, $installation->name, $installation->slug));
        if ($generated) {
            $output->writeln('Senha gerada (exibida só agora; guarde-a com segurança): ' . $password->reveal());
        }

        return Command::SUCCESS;
    }

    private function askPassword(InputInterface $input, OutputInterface $output): ?SensitiveValue
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $first = new Question('Senha (não será exibida): ');
        $first->setHidden(true);
        $first->setHiddenFallback(false);
        $second = new Question('Repita a senha: ');
        $second->setHidden(true);
        $second->setHiddenFallback(false);

        $password = new SensitiveValue((string) $helper->ask($input, $output, $first));
        $confirmation = new SensitiveValue((string) $helper->ask($input, $output, $second));

        if (!hash_equals($password->reveal(), $confirmation->reveal())) {
            $output->writeln('<error>As senhas não conferem. Nada foi criado.</error>');

            return null;
        }
        $problems = PasswordHasher::policyViolations($password);
        if ($problems !== []) {
            $output->writeln('<error>' . implode(' ', $problems) . ' Nada foi criado.</error>');

            return null;
        }

        return $password;
    }
}
