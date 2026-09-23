<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Shared\Config\SensitiveValue;

/** Tokens OAuth recebidos do Mercado Livre. Valores só em SensitiveValue. */
final readonly class TokenSet
{
    public function __construct(
        public SensitiveValue $accessToken,
        public ?SensitiveValue $refreshToken,
        public int $expiresIn,
        public ?string $scope,
        public ?int $userId,
        public string $tokenType,
    ) {
    }

    public function expiresAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->add(new \DateInterval('PT' . $this->expiresIn . 'S'));
    }
}
