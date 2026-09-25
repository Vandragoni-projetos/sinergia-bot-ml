<?php

declare(strict_types=1);

namespace Sinergia\Application\Niche;

/** Nicho do catálogo global oferecido às contas (só nomes amigáveis; sem categorias do ML). */
final readonly class CatalogNiche
{
    /** @param list<CatalogSubniche> $subniches */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public array $subniches,
    ) {
    }

    public function subnicheBySlug(string $slug): ?CatalogSubniche
    {
        foreach ($this->subniches as $subniche) {
            if ($subniche->slug === $slug) {
                return $subniche;
            }
        }

        return null;
    }
}
