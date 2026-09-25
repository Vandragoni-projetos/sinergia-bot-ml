<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

/** Operação recusada com um motivo exibível por código (nunca dados de outra conta). */
final class DestinationRejected extends \RuntimeException
{
    public const string NOT_FOUND = 'not_found';
    public const string WHATSAPP_NOT_CONNECTED = 'whatsapp_not_connected';
    public const string NOT_READY = 'not_ready';
    public const string NOT_ELIGIBLE = 'not_eligible';
    public const string NOT_A_GROUP = 'not_a_group';
    public const string INVALID_NICHE = 'invalid_niche';
    public const string TEST_SEND_DISABLED = 'test_send_disabled';
    public const string CONFIRMATION_REQUIRED = 'confirmation_required';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Operação de destino recusada: ' . $reason . '.');
    }
}
