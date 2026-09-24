<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/** State OAuth recusado. A mensagem nunca contém o valor do state. */
final class OAuthStateRejected extends \RuntimeException
{
    public const string UNKNOWN = 'state_unknown';
    public const string EXPIRED = 'state_expired';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN, 'State OAuth desconhecido ou já utilizado.');
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED, 'State OAuth expirado. Inicie o fluxo novamente.');
    }
}
