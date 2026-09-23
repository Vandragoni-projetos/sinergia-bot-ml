<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Exception;

/** 403 — sem permissão/escopo, IP bloqueado, app bloqueada ou dado de outro usuário (docs "Erro 403"). */
final class ForbiddenException extends HttpErrorException
{
}
