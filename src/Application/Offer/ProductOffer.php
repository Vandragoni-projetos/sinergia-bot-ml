<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

/** Uma oferta (anúncio) de um produto de catálogo, de /products/{id}/items ou do buy_box_winner. Preços em centavos. */
final readonly class ProductOffer
{
    public function __construct(
        public string $itemId,
        public int $priceCents,
        public ?int $originalPriceCents,
        public string $currencyId,
        public ?string $condition,
        public bool $freeShipping,
        public bool $officialStore,
    ) {
    }

    /** Oferta utilizável: BRL, preço positivo e produto novo (condição ausente é aceita e fica documentada). */
    public function isEligible(): bool
    {
        return $this->currencyId === 'BRL' && $this->priceCents > 0 && ($this->condition === null || $this->condition === 'new');
    }

    /** Desconto inteiro (arredondado para baixo); 0 sem preço anterior ou quando ele não é maior que o atual. */
    public function discountPct(): int
    {
        if ($this->originalPriceCents === null || $this->originalPriceCents <= $this->priceCents) {
            return 0;
        }

        return intdiv(($this->originalPriceCents - $this->priceCents) * 100, $this->originalPriceCents);
    }

    /** Preço anterior só vale quando é maior que o atual. */
    public function effectiveOriginalCents(): ?int
    {
        return $this->originalPriceCents !== null && $this->originalPriceCents > $this->priceCents ? $this->originalPriceCents : null;
    }
}
