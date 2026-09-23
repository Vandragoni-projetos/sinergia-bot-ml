<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Exception;

/** Parâmetro rejeitado ANTES de qualquer chamada de rede (ex.: ID de categoria malformado). */
final class InvalidArgumentException extends MercadoLivreException
{
}
