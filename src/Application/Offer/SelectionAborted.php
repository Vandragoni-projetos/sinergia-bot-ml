<?php

declare(strict_types=1);

namespace Sinergia\Application\Offer;

/** Interrompe a seleção (credencial da conta recusada com 401): nenhum candidato novo é gravado. */
final class SelectionAborted extends \RuntimeException
{
}
