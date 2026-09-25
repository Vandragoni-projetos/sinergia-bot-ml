<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Application\Niche\NicheFilters;
use Sinergia\Application\Offer\SelectOffers;
use Sinergia\Infrastructure\Persistence\InstallationRepository;
use Sinergia\Infrastructure\Persistence\OfferSelectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Executa o seletor de ofertas para UMA conta (slug) com o token Mercado Livre dela e mostra os candidatos.
 * Só leitura na API (GET). Não envia nada, não gera link, não agenda.
 */
#[AsCommand(name: 'offers:select', description: 'Seleciona ofertas candidatas para uma conta, a partir dos nichos escolhidos.')]
final class OffersSelectCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('installation', InputArgument::REQUIRED, 'Slug da conta (installation)')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Só mostra os candidatos da última execução, sem chamar a API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $installation = $this->container->get(InstallationRepository::class)->findBySlug(trim((string) $input->getArgument('installation')));
        if ($installation === null) {
            $output->writeln('<error>Conta não encontrada.</error>');

            return Command::FAILURE;
        }

        if (!$input->getOption('show')) {
            $report = $this->container->get(SelectOffers::class)->run($installation->id);
            $output->writeln(sprintf('Execução #%d: %s · %d chamadas à API · %d candidatos', $report->runId, $report->status, $report->apiCalls, count($report->candidates)));
            $stats = $report->stats;
            $output->writeln(sprintf(
                'Categorias: %d (cache %d, antigas %d, falhas %s) · entradas no ranking: %d · duplicados: %d · produtos avaliados: %d',
                $stats['categories'], $stats['categories_from_cache'], $stats['categories_stale'],
                json_encode($stats['categories_failed']), $stats['ranking_entries'], $stats['duplicates'], $stats['products_evaluated'],
            ));
            $output->writeln('Descartes: ' . json_encode($stats['rejections'], JSON_UNESCAPED_UNICODE));
            if ($stats['low_volume_categories'] !== []) {
                $output->writeln('<comment>Categorias com pouco volume: ' . count($stats['low_volume_categories']) . '</comment>');
            }
            if ($report->status === 'auth_failed') {
                $output->writeln('<error>A conexão Mercado Livre desta conta foi recusada. Reconecte em Conexões.</error>');

                return Command::FAILURE;
            }
        }

        foreach ($this->container->get(OfferSelectionRepository::class)->latestCandidates($installation->id) as $c) {
            $output->writeln(sprintf(
                '%3d. [%s › %s] %s — R$ %s%s%s',
                $c['position'],
                $c['niche'],
                $c['subniche'],
                mb_substr($c['name'], 0, 60),
                NicheFilters::formatCents($c['price_cents']),
                $c['original_cents'] === null ? ' (sem preço anterior)' : sprintf(' (de R$ %s, -%d%%)', NicheFilters::formatCents($c['original_cents']), $c['discount_pct']),
                $c['has_photo'] ? '' : ' [sem foto]',
            ));
        }

        return Command::SUCCESS;
    }
}
