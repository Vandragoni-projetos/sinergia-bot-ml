<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** Resultado da confirmação. */
final readonly class ImportReport
{
    public function __construct(
        public int $created,
        public int $replaced,
        public int $reused,
        public int $leftUnmatched,
        /** Produtos da posição de uma recusa do Gerador: retirados dos próximos lotes (affiliate_program_rejected). */
        public int $rejected = 0,
    ) {
    }
}
