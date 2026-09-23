<?php

declare(strict_types=1);

namespace Sinergia\Domain\Installation;

/** Identidade da instalação (cliente). Obrigatória em toda operação de dados de cliente. */
final readonly class InstallationId
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('InstallationId deve ser positivo.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
