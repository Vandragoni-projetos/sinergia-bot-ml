<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * URL do ANÚNCIO (item_id) entregue ao Gerador de Links oficial, montada de forma determinística:
 *   MLB4445311021 → https://produto.mercadolivre.com.br/MLB-4445311021
 * Validado manualmente (etapa 12C): o Gerador aceita essa URL e o link gerado abre o produto com o preço do anúncio.
 *
 * Os quatro conceitos NÃO se confundem:
 *   catalog_product_id (MLB41221309)  identidade do produto;
 *   item_id            (MLB4445311021) anúncio/oferta escolhida;
 *   source_url         (esta URL)      o que o cliente leva ao Gerador;
 *   affiliate_url      (meli.la/…)     o que o cliente cola de volta, guardado exatamente como veio.
 * Sem item_id válido não existe URL: nada de /p/{produto} ou permalink como substituto silencioso.
 */
final class OfferItemUrl
{
    public const string PREFIX = 'https://produto.mercadolivre.com.br/MLB-';

    public static function fromItemId(?string $itemId): ?string
    {
        return $itemId !== null && preg_match('/^MLB([0-9]{1,20})$/D', $itemId, $m) === 1 ? self::PREFIX . $m[1] : null;
    }

    public static function isValidItemId(?string $itemId): bool
    {
        return self::fromItemId($itemId) !== null;
    }
}
