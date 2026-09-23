<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/**
 * Uma entrada do ranking oficial de mais vendidos.
 * ATENÇÃO: posição é RANKING, não quantidade vendida. Nunca converter em "vendas".
 */
final readonly class HighlightEntry
{
    /** Tipos documentados em "Mais vendidos no Mercado Livre" (22/06/2026). */
    public const array DOCUMENTED_TYPES = ['ITEM', 'PRODUCT', 'USER_PRODUCT'];

    public function __construct(
        public string $id,
        public int $position,
        public ?string $type,
    ) {
    }

    public function isDocumentedType(): bool
    {
        return $this->type !== null && in_array($this->type, self::DOCUMENTED_TYPES, true);
    }

    /** @return array{position: int, id: string, type: ?string} */
    public function toArray(): array
    {
        return ['position' => $this->position, 'id' => $this->id, 'type' => $this->type];
    }
}
