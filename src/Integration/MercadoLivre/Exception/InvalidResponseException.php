<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Exception;

/** Resposta 2xx que não segue o contrato documentado (vazia, JSON inválido, campos obrigatórios ausentes). */
final class InvalidResponseException extends MercadoLivreException
{
}
