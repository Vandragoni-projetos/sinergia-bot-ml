<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Domain\Installation\Installation;
use Sinergia\Infrastructure\Persistence\MlCategoryRepository;
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
    name: 'ml:highlights:check',
    description: 'Validação (somente leitura) de /highlights/{site}/category/{id}: ranking oficial, sem vendas.',
)]
final class HighlightsCheckCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('category_id', InputArgument::REQUIRED, 'Categoria-folha oficial (obtida via ml:category:check)')
            ->addOption('auth', null, InputOption::VALUE_REQUIRED, 'none (padrão, sem token) | oauth (só se pedido explicitamente)', 'none')
            ->addOption('attribute', null, InputOption::VALUE_REQUIRED, 'Filtro opcional, ex.: BRAND')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'ID do valor do atributo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $auth = (string) $input->getOption('auth');
        if (!in_array($auth, ['oauth', 'none'], true)) {
            $output->writeln('<error>--auth deve ser oauth ou none.</error>');

            return Command::INVALID;
        }
        $categoryId = strtoupper((string) $input->getArgument('category_id'));
        $attribute = $input->getOption('attribute');
        $value = $input->getOption('value');

        /** @var Installation $installation */
        $installation = $this->container->get(Installation::class);
        $site = $this->container->get(Config::class)->siteId();
        $cached = $this->container->get(MlCategoryRepository::class)->find($site, $categoryId);
        $name = is_array($cached) ? (string) $cached['name'] : null;

        $outcome = Kernel::validateHighlights($this->container, $auth === 'oauth')(
            $installation->id,
            $site,
            $categoryId,
            $auth === 'oauth' ? AuthMode::Required : AuthMode::None,
            is_string($attribute) ? $attribute : null,
            is_string($value) ? $value : null,
            $name,
        );

        $output->writeln(sprintf('discovery_run_id=%d  correlation_id=%s  auth=%s', $outcome->runId, $outcome->correlationId, $auth));
        $output->writeln('Categoria: ' . ($name ?? '(nome não cacheado — rode ml:category:check antes)'));
        $output->writeln('ID:        ' . $categoryId);
        if (!$outcome->succeeded) {
            $output->writeln(sprintf('<error>FALHOU: HTTP %s | %s | %s</error>', $outcome->httpStatus ?? '-', $outcome->errorCode, $outcome->errorMessage));
            $output->writeln('Evidência: ' . ($outcome->evidenceFile ?? '-'));

            return Command::FAILURE;
        }

        $s = $outcome->summary;
        $output->writeln(sprintf('Highlights retornados: %d  (highlight_type=%s, criteria=%s)', $s['items_found'], $s['highlight_type'] ?? '-', $s['criteria'] ?? '-'));
        foreach ($s['entries'] as $entry) {
            $output->writeln(sprintf('  #%-3d %-18s %s', $entry['position'], $entry['id'], $entry['type'] ?? '(sem type)'));
        }
        foreach ($s['warnings'] as $warning) {
            $output->writeln('<comment>Aviso: ' . $warning . '</comment>');
        }
        $output->writeln('Posição = ranking oficial da categoria. NÃO é quantidade vendida.');
        $output->writeln('HTTP ' . $outcome->httpStatus . ' | evidência: ' . $outcome->evidenceFile);

        return Command::SUCCESS;
    }
}
