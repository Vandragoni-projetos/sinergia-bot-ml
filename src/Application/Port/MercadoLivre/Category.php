<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/** Categoria oficial conforme /categories/{id}. Campos opcionais ausentes ficam null. */
final readonly class Category
{
    /**
     * @param list<CategoryRef> $pathFromRoot
     * @param list<CategoryRef> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $pathFromRoot,
        public array $children,
        public ?string $catalogDomain,
        public ?int $totalItems,
        public ?string $permalink,
        public \DateTimeImmutable $fetchedAt,
        public ?ResponseMeta $meta = null,
    ) {
    }

    public function isLeaf(): bool
    {
        return $this->children === [];
    }

    /** Pai = penúltimo nó do path_from_root (o último é a própria categoria). */
    public function parentId(): ?string
    {
        $count = count($this->pathFromRoot);

        return $count >= 2 ? $this->pathFromRoot[$count - 2]->id : null;
    }

    /** @return list<array{id: string, name: string, total_items: ?int}> */
    public function pathArray(): array
    {
        return array_map(static fn (CategoryRef $r): array => $r->toArray(), $this->pathFromRoot);
    }

    /** @return list<array{id: string, name: string, total_items: ?int}> */
    public function childrenArray(): array
    {
        return array_map(static fn (CategoryRef $r): array => $r->toArray(), $this->children);
    }

    public function pathLabel(string $separator = ' > '): string
    {
        return implode($separator, array_map(static fn (CategoryRef $r): string => $r->name, $this->pathFromRoot));
    }
}
