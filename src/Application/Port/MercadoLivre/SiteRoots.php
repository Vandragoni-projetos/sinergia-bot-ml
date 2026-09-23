<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/** Categorias de primeiro nível de um site (/sites/{site_id}/categories). */
final readonly class SiteRoots
{
    /** @param list<CategoryRef> $roots */
    public function __construct(
        public string $siteId,
        public array $roots,
        public ResponseMeta $meta,
    ) {
    }

    public function count(): int
    {
        return count($this->roots);
    }
}
