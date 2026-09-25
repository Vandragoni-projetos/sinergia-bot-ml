<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

/**
 * Produto de catálogo (MLB…) vindo de GET /products/{id}: só o necessário para a oferta.
 * Dado PÚBLICO e compartilhável entre contas (não contém nada de nenhum tenant).
 */
final readonly class CatalogProduct
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $domainId,
        public ?string $permalink,
        public ?string $pictureUrl,
        public int $picturesCount,
        public ?string $catalogStatus,
        public ?ProductOffer $buyBoxWinner,
    ) {
    }

    public function hasPhoto(): bool
    {
        return $this->pictureUrl !== null;
    }
}
