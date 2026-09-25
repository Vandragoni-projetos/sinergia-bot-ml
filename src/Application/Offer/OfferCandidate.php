<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

/** Oferta candidata aprovada para UMA conta (saída do seletor; entrada da fila na etapa 8). */
final readonly class OfferCandidate
{
    public function __construct(
        public CatalogProduct $product,
        public ChosenOffer $offer,
        public SelectionTarget $target,
        public int $rankingPosition,
    ) {
    }
}
