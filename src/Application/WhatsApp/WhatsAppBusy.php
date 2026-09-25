<?php

declare(strict_types=1);

namespace Sinergia\Application\WhatsApp;

/** Outra operação de WhatsApp da mesma conta está em andamento (trava ocupada). */
final class WhatsAppBusy extends \RuntimeException
{
}
