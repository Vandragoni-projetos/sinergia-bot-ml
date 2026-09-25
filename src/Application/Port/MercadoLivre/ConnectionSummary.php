<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/** Situação da conexão Mercado Livre de uma conta, sem nenhum token. */
final readonly class ConnectionSummary
{
    public function __construct(
        public string $status,
        public ?int $mlUserId,
        public \DateTimeImmutable $accessExpiresAt,
        public bool $hasRefreshToken,
        public \DateTimeImmutable $connectedAt,
        public ?\DateTimeImmutable $lastRefreshAt,
    ) {
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }
}
