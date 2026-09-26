<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

/** Oferta escolhida para um produto (dado público, cacheado por produto). */
final readonly class ChosenOffer
{
    public const string RULE_BUY_BOX = 'buy_box';
    public const string RULE_LOWEST_PRICE = 'lowest_price';
    /** No envio: o anúncio para o qual o link de afiliado foi gerado (nunca outro). */
    public const string RULE_LINKED_OFFER = 'linked_offer';

    public function __construct(
        public string $itemId,
        public int $priceCents,
        public ?int $originalPriceCents,
        public int $discountPct,
        public string $rule,
        public bool $freeShipping,
        public bool $officialStore,
        public ?int $offersTotal,
        public ?int $offersEligible,
    ) {
    }

    public function hasOriginalPrice(): bool
    {
        return $this->originalPriceCents !== null;
    }
}
