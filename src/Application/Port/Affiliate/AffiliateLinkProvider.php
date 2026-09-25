<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Affiliate;

use Sinergia\Application\Affiliate\AffiliateLink;
use Sinergia\Application\Affiliate\BatchRejected;
use Sinergia\Application\Affiliate\BatchView;
use Sinergia\Application\Affiliate\ImportReport;
use Sinergia\Application\Affiliate\LinkRequestResult;
use Sinergia\Application\Affiliate\ProductToLink;
use Sinergia\Domain\Installation\InstallationId;

/**
 * Obtenção de links de afiliado (plano F1 v2.1, seção 4).
 * F1: ManualBatchAffiliateLinkProvider ('manual_batch'). Futuro: 'official_api', sem mudar fila, nichos ou destinos.
 * Tudo é por conta (installation_id); um link de uma conta nunca é devolvido para outra.
 */
interface AffiliateLinkProvider
{
    public const string MANUAL_BATCH = 'manual_batch';
    public const string OFFICIAL_API = 'official_api';

    public function mode(): string;

    /**
     * Links ATIVOS da biblioteca da conta para os produtos informados.
     *
     * @param list<string> $productIds
     *
     * @return array<string, AffiliateLink> por ml_product_id
     */
    public function findExisting(InstallationId $installation, array $productIds): array;

    /**
     * Pede links: official_api devolveria links; manual_batch devolve os existentes e marca o resto como pendente.
     *
     * @param list<ProductToLink> $products
     */
    public function request(InstallationId $installation, array $products): LinkRequestResult;

    /**
     * Cria o lote com as URLs originais (sem alterar, encurtar ou abrir nenhuma).
     *
     * @param list<ProductToLink> $products
     *
     * @throws BatchRejected
     */
    public function exportBatch(InstallationId $installation, int $userId, array $products): BatchView;

    /**
     * Registra o texto colado e devolve a pré-visualização. NÃO grava nada na biblioteca.
     *
     * @throws BatchRejected
     */
    public function previewImport(InstallationId $installation, string $batchKey, string $pastedText): BatchView;

    /**
     * Grava na biblioteca SOMENTE as associações escolhidas pelo cliente (linha → item do lote).
     *
     * @param array<int, string> $associations número da linha → item_key
     *
     * @throws BatchRejected
     */
    public function confirmImport(InstallationId $installation, string $batchKey, array $associations, int $userId): ImportReport;
}
