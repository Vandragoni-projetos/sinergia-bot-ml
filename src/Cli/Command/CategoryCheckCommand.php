<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ml:category:check',
    description: 'Validação (somente leitura) da árvore oficial: raízes do site ou uma categoria (nome, pai, path, filhos, domínio).',
)]
final class CategoryCheckCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('category_id', InputArgument::OPTIONAL, 'ID oficial (ex.: MLB1234). Omitido = raízes do site.')
            ->addOption('auth', null, InputOption::VALUE_REQUIRED, 'none (padrão, sem token) | oauth (só se pedido explicitamente)', 'none');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $auth = (string) $input->getOption('auth');
        if (!in_array($auth, ['oauth', 'none'], true)) {
            $output->writeln('<error>--auth deve ser oauth ou none.</error>');

            return Command::INVALID;
        }
        $categoryId = $input->getArgument('category_id');
        $categoryId = is_string($categoryId) && $categoryId !== '' ? strtoupper($categoryId) : null;

        /** @var Installation $installation */
        $installation = $this->container->get(Installation::class);
        $site = $this->container->get(Config::class)->siteId();
        $useCase = Kernel::validateCategory($this->container, $auth === 'oauth');
        $outcome = $useCase($installation->id, $site, $categoryId, $auth === 'oauth' ? AuthMode::Required : AuthMode::None);

        $output->writeln(sprintf('discovery_run_id=%d  correlation_id=%s  auth=%s', $outcome->runId, $outcome->correlationId, $auth));
        if (!$outcome->succeeded) {
            $output->writeln(sprintf('<error>FALHOU: HTTP %s | %s | %s</error>', $outcome->httpStatus ?? '-', $outcome->errorCode, $outcome->errorMessage));
            $output->writeln('Evidência: ' . ($outcome->evidenceFile ?? '-'));

            return Command::FAILURE;
        }

        $s = $outcome->summary;
        if ($categoryId === null) {
            $output->writeln(sprintf('Raízes de %s (%d):', $site, count($s['roots'])));
            foreach ($s['roots'] as $root) {
                $output->writeln(sprintf('  %-12s %s', $root['id'], $root['name']));
            }
        } else {
            $output->writeln(sprintf('Categoria: %s  (%s)', $s['name'], $s['id']));
            $output->writeln('Caminho:   ' . $s['path_label']);
            $output->writeln('Pai:       ' . ($s['parent_id'] ?? '(raiz)'));
            $output->writeln('Folha:     ' . ($s['is_leaf'] ? 'sim' : 'não'));
            $output->writeln('Domínio:   ' . ($s['catalog_domain'] ?? '(não informado)'));
            $output->writeln(sprintf('Filhos (%d):', count($s['children'])));
            foreach ($s['children'] as $child) {
                $output->writeln(sprintf('  %-12s %s', $child['id'], $child['name']));
            }
        }
        $output->writeln('HTTP ' . $outcome->httpStatus . ' | evidência: ' . $outcome->evidenceFile);

        return Command::SUCCESS;
    }
}
