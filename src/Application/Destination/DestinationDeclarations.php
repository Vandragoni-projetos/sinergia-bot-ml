<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

/** Textos versionados das declarações por destino (a versão é gravada junto com data e usuário). */
final class DestinationDeclarations
{
    public const string VERSION = 'v1';

    public const string PUBLIC_GROUP = 'public_group';
    public const string MEDIA_REGISTERED = 'media_registered';

    public const array TEXTS = [
        self::PUBLIC_GROUP => 'Declaro que este grupo é público e aberto: qualquer pessoa pode entrar pelo link de convite divulgado publicamente, sem aprovação.',
        self::MEDIA_REGISTERED => 'Declaro que este destino está cadastrado como Mídia no meu perfil de afiliado do Mercado Livre.',
    ];
}
