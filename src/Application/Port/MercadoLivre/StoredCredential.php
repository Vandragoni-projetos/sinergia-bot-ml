<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Shared\Config\SensitiveValue;

/** Conexão OAuth persistida de uma instalação (tokens já decifrados, em SensitiveValue). */
final readonly class StoredCredential
{
    public function __construct(
        public string $clientId,
        public ?int $mlUserId,
        public ?string $scopes,
        public SensitiveValue $accessToken,
        public ?SensitiveValue $refreshToken,
        public \DateTimeImmutable $accessExpiresAt,
        public ?\DateTimeImmutable $refreshObtainedAt,
        public string $status,
        public ?\DateTimeImmutable $lastApiCallAt,
    ) {
    }

    public function accessValidFor(\DateTimeImmutable $now, int $marginSeconds): bool
    {
        return $this->accessExpiresAt > $now->add(new \DateInterval('PT' . $marginSeconds . 'S'));
    }
}
