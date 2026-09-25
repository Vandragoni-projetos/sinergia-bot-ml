<?php

declare(strict_types=1);

namespace Sinergia\Web\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * IP do cliente para limitar tentativas de login.
 * Atrás do proxy do EasyPanel (endereço de rede privada), usa o ÚLTIMO item do X-Forwarded-For,
 * que é o que o próprio proxy acrescentou; valores anteriores podem ter sido forjados pelo cliente.
 */
final class ClientIp
{
    public static function from(ServerRequestInterface $request): string
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $isPublic = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if ($isPublic) {
            return $remote;
        }

        $forwarded = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));
        $last = (string) end($forwarded);
        if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
            return $last;
        }

        return $remote !== '' ? $remote : 'unknown';
    }
}
