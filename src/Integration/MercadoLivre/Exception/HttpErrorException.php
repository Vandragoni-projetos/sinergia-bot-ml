<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Exception;

/** Resposta HTTP de erro (4xx/5xx). Subclasses específicas para os status relevantes. */
class HttpErrorException extends MercadoLivreException
{
}
