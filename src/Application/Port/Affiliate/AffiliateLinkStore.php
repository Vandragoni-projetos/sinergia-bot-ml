<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Affiliate;

use Sinergia\Application\Affiliate\AffiliateLink;
use Sinergia\Application\Affiliate\BatchPreview;
use Sinergia\Application\Affiliate\BatchView;
use Sinergia\Application\Affiliate\ImportReport;
use Sinergia\Application\Affiliate\ProductToLink;
use Sinergia\Domain\Installation\InstallationId;

/** Biblioteca e lotes por conta. TODA leitura/escrita é restrita ao installation_id recebido. */
interface AffiliateLinkStore
{
    /**
     * Candidatos da última seleção da conta (etapa 4) SEM link ativo na biblioteca da conta.
     *
     * @return list<ProductToLink>
     */
    public function productsAwaitingLink(InstallationId $installation, int $limit): array;

    public function countAwaitingLink(InstallationId $installation): int;

    /**
     * @param list<string> $productIds
     *
     * @return array<string, AffiliateLink>
     */
    public function activeLinks(InstallationId $installation, array $productIds): array;

    public function openBatch(InstallationId $installation, \DateTimeImmutable $now): ?BatchView;

    public function batch(InstallationId $installation, string $key): ?BatchView;

    /** @param list<ProductToLink> $products */
    public function createBatch(InstallationId $installation, int $userId, array $products, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): BatchView;

    public function savePreview(InstallationId $installation, int $batchId, BatchPreview $preview, \DateTimeImmutable $now): void;

    /**
     * Grava as associações escolhidas numa transação: cria link ativo, reaproveita o idêntico ou substitui o anterior
     * (que vira 'replaced'). Itens não escolhidos ficam 'unmatched'.
     *
     * @param list<array{item_id: int, line_no: int, evidence: string}> $associations
     */
    public function confirm(InstallationId $installation, int $batchId, array $associations, int $userId, \DateTimeImmutable $now): ImportReport;

    public function cancelBatch(InstallationId $installation, int $batchId): void;
}
