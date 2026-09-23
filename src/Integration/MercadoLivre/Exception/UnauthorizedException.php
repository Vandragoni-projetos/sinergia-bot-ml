<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Exception;

/** 401 — token ausente, inválido ou expirado. */
final class UnauthorizedException extends HttpErrorException
{
}
