<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * Produto que precisa de link: identidade de catálogo + anúncio (item_id) da oferta escolhida. A URL entregue ao
 * Gerador é a do anúncio (OfferItemUrl), sem alteração. Sem item_id confiável o produto fica BLOQUEADO para
 * exportação (originalUrl = null, motivo em blockedReason): nunca se inventa outra URL.
 */
final readonly class ProductToLink
{
    public const string BLOCKED_NO_OFFER = 'no_confirmed_offer';

    public ?string $originalUrl;
    public ?string $blockedReason;

    public function __construct(
        public string $productId,
        public ?string $offerItemId,
        public string $name,
    ) {
        $this->originalUrl = OfferItemUrl::fromItemId($offerItemId);
        $this->blockedReason = $this->originalUrl === null ? self::BLOCKED_NO_OFFER : null;
    }

    public function exportable(): bool
    {
        return $this->originalUrl !== null;
    }
}
