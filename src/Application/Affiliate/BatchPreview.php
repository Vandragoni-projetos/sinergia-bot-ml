<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** Resultado da pré-visualização: nada é gravado na biblioteca até a confirmação. */
final readonly class BatchPreview
{
    public const string COUNT_MISMATCH = 'count_mismatch';
    public const string EMPTY_LINE = 'empty_line';
    public const string DUPLICATE = 'duplicate';
    public const string INVALID_DOMAIN = 'invalid_domain';
    public const string INVALID_LINE = 'invalid_line';
    public const string ID_CONFLICT = 'id_conflict';

    /**
     * @param list<ReceivedLine> $lines
     * @param list<string>       $anomalies
     */
    public function __construct(
        public array $lines,
        public array $anomalies,
    ) {
    }

    public function hasAnomalies(): bool
    {
        return $this->anomalies !== [];
    }
}
