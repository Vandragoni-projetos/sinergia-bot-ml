<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * Produto de um lote exportado, na posição em que a URL original (a do anúncio offerItemId) foi entregue ao cliente.
 * offerItemId é null só em lotes antigos, anteriores ao vínculo link ↔ oferta.
 */
final readonly class ExportedItem
{
    public function __construct(
        public int $id,
        public string $itemKey,
        public int $position,
        public string $productId,
        public string $originalUrl,
        public string $productName,
        public string $matchStatus = 'unmatched',
        public ?string $receivedRaw = null,
        public ?string $offerItemId = null,
    ) {
    }
}
