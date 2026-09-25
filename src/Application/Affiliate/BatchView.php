<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** Lote com itens (ordem exportada) e, se já colado, as linhas recebidas. */
final readonly class BatchView
{
    /**
     * @param list<ExportedItem> $items
     * @param list<ReceivedLine> $lines
     * @param list<string>       $anomalies
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $status,
        public array $items,
        public array $lines,
        public array $anomalies,
        public \DateTimeImmutable $exportedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['exported', 'preview'], true);
    }

    public function exportText(): string
    {
        return implode("\n", array_map(static fn (ExportedItem $i): string => $i->originalUrl, $this->items));
    }
}
