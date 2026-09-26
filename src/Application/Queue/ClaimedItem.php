<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Sinergia\Application\Niche\NicheFilters;

/**
 * Item reservado por UM worker (claim_token) + tudo o que é revalidado antes do envio, lido na hora e
 * sempre pela conta do item: destino, link, produto, filtros do nicho, bot da conta.
 */
final readonly class ClaimedItem
{
    public function __construct(
        public int $id,
        public string $claimToken,
        public int $attempts,
        public string $productId,
        public int $nicheId,
        public ?int $affiliateLinkId,
        public ?string $decidedBy,
        public bool $botActive,
        public ?int $destinationId,
        public ?string $destinationStatus,
        public ?string $destinationMode,
        public ?string $destinationRef,
        public ?string $windowStart,
        public ?string $windowEnd,
        public ?int $intervalMinutes,
        public ?\DateTimeImmutable $destinationLastSentAt,
        public string $timezone,
        public ?int $currentLinkId,
        public ?string $currentLinkUrl,
        public ?string $productName,
        public ?string $productPicture,
        public NicheFilters $filters,
        /** Nomes do catálogo global de nichos (só para a copy por IA; nenhum dado da conta). */
        public ?string $nicheName = null,
        public ?string $subnicheName = null,
        /** Anúncio (item_id) para o qual o link ativo foi gerado; null em links antigos (sem vínculo). */
        public ?string $currentLinkOfferItemId = null,
    ) {
    }
}
