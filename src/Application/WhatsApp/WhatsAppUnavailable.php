<?php

declare(strict_types=1);

namespace Sinergia\Application\WhatsApp;

/** Provedor de WhatsApp não configurado no servidor (sem UAZAPI_*). */
final class WhatsAppUnavailable extends \RuntimeException
{
}
