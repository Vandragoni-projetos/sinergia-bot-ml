<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

final readonly class CategoryRef
{
    public function __construct(
        public string $id,
        public string $name,
        public ?int $totalItems = null,
    ) {
    }

    /** @return array{id: string, name: string, total_items: ?int} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'total_items' => $this->totalItems];
    }
}
