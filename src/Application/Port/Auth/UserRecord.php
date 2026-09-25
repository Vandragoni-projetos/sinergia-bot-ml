<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Auth;

use Sinergia\Domain\Installation\InstallationId;

/** Usuário do painel como lido do armazenamento (inclui o status da conta a que pertence). */
final readonly class UserRecord
{
    public function __construct(
        public int $id,
        public InstallationId $installationId,
        public string $installationName,
        public string $installationStatus,
        public string $email,
        public string $name,
        public string $passwordHash,
        public string $status,
    ) {
    }

    public function canSignIn(): bool
    {
        return $this->status === 'active' && $this->installationStatus === 'active';
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'installation_id' => $this->installationId->value, 'status' => $this->status];
    }
}
