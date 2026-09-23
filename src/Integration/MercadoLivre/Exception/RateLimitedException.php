<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Exception;

/** 429 — o chamador deve aplicar backoff (e respeitar Retry-After quando presente). */
final class RateLimitedException extends HttpErrorException
{
}
