<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * Único formato observado na saída real do Gerador de Links oficial (amostra de 2026-09-25):
 *   https://meli.la/<código>   — um por linha, código alfanumérico (ex.: 7 caracteres, com maiúsculas e minúsculas).
 * O link curto NÃO carrega o ID do produto: a correspondência possível é só pela posição (evidência fraca).
 * Outros formatos do Mercado Livre não são aceitos enquanto não houver amostra real deles.
 */
final class MeliLaShortLinkFormat implements AffiliateUrlFormat
{
    /** Domínio oficial dos links curtos do Gerador de Links do Mercado Livre (validação só textual; nunca é acessado). */
    public const string HOST = 'meli.la';

    public function accepts(string $url): bool
    {
        return preg_match('#^https://' . preg_quote(self::HOST, '#') . '/[A-Za-z0-9]{4,32}$#', $url) === 1;
    }

    public function productId(string $url): ?string
    {
        return null;
    }

    /** Parece um link (tem esquema e host), mas de outro domínio? Usado só para classificar o erro. */
    public static function looksLikeUrl(string $text): bool
    {
        return preg_match('#^https?://[^\s/]+#i', $text) === 1;
    }
}
