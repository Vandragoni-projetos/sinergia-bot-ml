<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Copy;

/**
 * Tudo o que a IA pode receber: título, nicho, subnicho e fatos booleanos JÁ confirmados pelo BotML.
 * Não existe campo para preço, preço anterior, desconto, link, telefone ou dado financeiro — por construção.
 */
final readonly class OfferCopyRequest
{
    public function __construct(
        public string $title,
        public ?string $nicheName,
        public ?string $subnicheName,
        public bool $freeShipping,
        public bool $officialStore,
    ) {
    }
}
