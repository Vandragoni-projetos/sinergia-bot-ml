<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use Sinergia\Application\Affiliate\AffiliateUrlFormat;

/**
 * Formato FICTÍCIO, só de teste, que carrega o ID do produto no caminho. O Gerador real observado (meli.la) não
 * traz ID; este formato existe apenas para exercitar a regra de evidência forte e de conflito.
 */
final class TestIdFormat implements AffiliateUrlFormat
{
    public function accepts(string $url): bool
    {
        return preg_match('#^https://ids\.example\.test/MLB\d+/[a-z]+$#', $url) === 1;
    }

    public function productId(string $url): ?string
    {
        return preg_match('#/(MLB\d+)/#', $url, $m) === 1 ? $m[1] : null;
    }
}
