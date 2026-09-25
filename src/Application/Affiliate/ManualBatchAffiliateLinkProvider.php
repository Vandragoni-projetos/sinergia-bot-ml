<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\Affiliate\AffiliateLinkProvider;
use Sinergia\Application\Port\Affiliate\AffiliateLinkStore;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;

/**
 * F1: links pelo Gerador de Links OFICIAL, em lote e com ação humana. A aplicação nunca abre, encurta, edita ou
 * segue link nenhum: exporta as URLs originais e valida o texto colado de volta.
 */
final class ManualBatchAffiliateLinkProvider implements AffiliateLinkProvider
{
    public const int MAX_BATCH_ITEMS = 50;
    public const int MAX_PASTE_BYTES = 200_000;
    public const int BATCH_TTL_HOURS = 48;

    public function __construct(
        private readonly AffiliateLinkStore $store,
        private readonly BatchMatcher $matcher,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mode(): string
    {
        return self::MANUAL_BATCH;
    }

    public function findExisting(InstallationId $installation, array $productIds): array
    {
        return $this->store->activeLinks($installation, array_values(array_unique($productIds)));
    }

    public function request(InstallationId $installation, array $products): LinkRequestResult
    {
        $existing = $this->findExisting($installation, array_map(static fn (ProductToLink $p): string => $p->productId, $products));
        $pending = [];
        foreach ($products as $product) {
            if (!isset($existing[$product->productId])) {
                $pending[] = $product->productId;
            }
        }

        return new LinkRequestResult($existing, array_values(array_unique($pending)));
    }

    public function exportBatch(InstallationId $installation, int $userId, array $products): BatchView
    {
        $now = $this->clock->now();
        $open = $this->store->openBatch($installation, $now);
        if ($open !== null) {
            return $open;
        }
        // Só produtos ainda sem link ativo, sem repetir, no máximo MAX_BATCH_ITEMS.
        $pending = array_flip($this->request($installation, $products)->pending);
        $selected = [];
        foreach ($products as $product) {
            if (isset($pending[$product->productId]) && !isset($selected[$product->productId])) {
                $selected[$product->productId] = $product;
            }
        }
        if ($selected === []) {
            throw new BatchRejected(BatchRejected::NOTHING_TO_EXPORT);
        }
        $batch = $this->store->createBatch($installation, $userId, array_slice(array_values($selected), 0, self::MAX_BATCH_ITEMS), $now, $now->modify('+' . self::BATCH_TTL_HOURS . ' hours'));
        $this->logger->info('affiliate.batch_exported', ['installation_id' => $installation->value, 'items' => count($batch->items)]);

        return $batch;
    }

    public function previewImport(InstallationId $installation, string $batchKey, string $pastedText): BatchView
    {
        $batch = $this->openOrFail($installation, $batchKey);
        if (strlen($pastedText) > self::MAX_PASTE_BYTES) {
            throw new BatchRejected(BatchRejected::TOO_LARGE);
        }
        if (trim($pastedText) === '') {
            throw new BatchRejected(BatchRejected::EMPTY_PASTE);
        }
        $preview = $this->matcher->preview($batch->items, $pastedText);
        $this->store->savePreview($installation, $batch->id, $preview, $this->clock->now());
        $this->logger->info('affiliate.batch_previewed', [
            'installation_id' => $installation->value, 'lines' => count($preview->lines), 'anomalies' => $preview->anomalies,
        ]);

        return $this->store->batch($installation, $batchKey) ?? throw new BatchRejected(BatchRejected::NOT_FOUND);
    }

    public function confirmImport(InstallationId $installation, string $batchKey, array $associations, int $userId): ImportReport
    {
        $batch = $this->openOrFail($installation, $batchKey);
        if ($batch->status !== 'preview') {
            throw new BatchRejected(BatchRejected::NOT_PREVIEWED);
        }
        $lines = [];
        foreach ($batch->lines as $line) {
            $lines[$line->lineNo] = $line;
        }
        $items = [];
        foreach ($batch->items as $item) {
            $items[$item->itemKey] = $item;
        }

        $chosen = [];
        $usedItems = [];
        foreach ($associations as $lineNo => $itemKey) {
            if ($itemKey === '') {
                continue;
            }
            $line = $lines[(int) $lineNo] ?? null;
            if ($line === null || !$line->isValid()) {
                throw new BatchRejected(BatchRejected::INVALID_LINE);
            }
            $item = $items[$itemKey] ?? null;
            if ($item === null || $item->matchStatus === 'confirmed') {
                throw new BatchRejected(BatchRejected::UNKNOWN_ITEM);
            }
            if (isset($usedItems[$itemKey])) {
                throw new BatchRejected(BatchRejected::ITEM_TWICE);
            }
            // Link que carrega OUTRA identidade de produto nunca é associado, nem manualmente.
            if ($line->detectedProductId !== null && $line->detectedProductId !== $item->productId) {
                throw new BatchRejected(BatchRejected::ID_CONFLICT);
            }
            $usedItems[$itemKey] = true;
            $chosen[] = [
                'item_id' => $item->id,
                'line_no' => $line->lineNo,
                'evidence' => match (true) {
                    $line->detectedProductId === $item->productId => 'product_id',
                    $line->proposedPosition === $item->position => 'position_only',
                    default => 'manual',
                },
            ];
        }
        if ($chosen === []) {
            throw new BatchRejected(BatchRejected::NOTHING_SELECTED);
        }

        $report = $this->store->confirm($installation, $batch->id, $chosen, $userId, $this->clock->now());
        $this->logger->info('affiliate.batch_confirmed', [
            'installation_id' => $installation->value, 'user_id' => $userId,
            'created' => $report->created, 'replaced' => $report->replaced, 'reused' => $report->reused, 'unmatched' => $report->leftUnmatched,
        ]);

        return $report;
    }

    /** Descarta o lote aberto da conta (nada é gravado na biblioteca). */
    public function cancel(InstallationId $installation, string $batchKey): void
    {
        $this->store->cancelBatch($installation, $this->openOrFail($installation, $batchKey)->id);
    }

    /** @throws BatchRejected */
    private function openOrFail(InstallationId $installation, string $batchKey): BatchView
    {
        $batch = preg_match('/^[a-f0-9]{20}$/', $batchKey) === 1 ? $this->store->batch($installation, $batchKey) : null;
        if ($batch === null) {
            throw new BatchRejected(BatchRejected::NOT_FOUND);
        }
        if (!$batch->isOpen() || $batch->expiresAt <= $this->clock->now()) {
            throw new BatchRejected(BatchRejected::CLOSED);
        }

        return $batch;
    }
}
