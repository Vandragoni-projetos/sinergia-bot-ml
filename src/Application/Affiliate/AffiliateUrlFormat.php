<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * Formato de link de afiliado aceito na importação. Validação SOMENTE textual: nunca abre, resolve ou segue o link.
 */
interface AffiliateUrlFormat
{
    /** O texto (já sem espaços nas pontas) é um link deste formato? */
    public function accepts(string $url): bool;

    /** Identidade do produto (MLB…) legível no próprio texto do link; null quando o formato não a carrega. */
    public function productId(string $url): ?string;
}
