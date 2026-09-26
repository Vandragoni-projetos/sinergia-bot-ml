<?php

declare(strict_types=1);

namespace Sinergia\Application\WhatsApp;

/**
 * A conta ainda não tem instância de WhatsApp atribuída pelo administrador (provedor sem criação automática,
 * ex.: Evolution API na opção C). O BotML não tenta criar instância.
 */
final class WhatsAppNotProvisioned extends \RuntimeException
{
}
