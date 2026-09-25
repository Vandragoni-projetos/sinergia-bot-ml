<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/**
 * Texto da declaração exigida pelo Programa de Afiliados (Mídias públicas e cadastradas no perfil).
 * Mudou o texto → mude a versão: declarações antigas ficam registradas com a versão que foi aceita.
 */
final class MediaDeclaration
{
    public const string VERSION = 'v1';
    public const string TEXT = 'Declaro que os canais e grupos onde o SINERGIA BOT ML vai publicar são públicos '
        . 'e estão cadastrados como Mídia no meu perfil de afiliado do Mercado Livre.';
}
