<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Persistence;

use Sinergia\Application\Port\WhatsApp\ConnectionSnapshot;
use Sinergia\Application\Port\WhatsApp\ProviderInstance;
use Sinergia\Application\Port\WhatsApp\WhatsAppConnectionStore;
use Sinergia\Application\WhatsApp\WhatsAppBusy;
use Sinergia\Application\WhatsApp\WhatsAppConnection;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Infrastructure\Crypto\SecretBox;

/** Conexão WhatsApp por conta. TODA consulta filtra por installation_id; o token é gravado cifrado. */
final class WhatsAppConnectionRepository implements WhatsAppConnectionStore
{
    public const int LOCK_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly SecretBox $box,
    ) {
    }

    public function withLock(InstallationId $installation, callable $work): mixed
    {
        $name = 'sbm:whatsapp:' . $installation->value;
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
        $stmt->execute(['name' => $name, 'timeout' => self::LOCK_TIMEOUT_SECONDS]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new WhatsAppBusy('Outra operação de WhatsApp desta conta está em andamento.');
        }
        try {
            return $work();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
        }
    }

    public function find(InstallationId $installation): ?WhatsAppConnection
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_connections WHERE installation_id = :inst');
        $stmt->execute(['inst' => $installation->value]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new WhatsAppConnection(
            (string) $row['instance_name'],
            $row['instance_token_enc'] === null ? null : $this->box->decrypt((string) $row['instance_token_enc']),
            (string) $row['status'],
            $row['connect_mode'] === null ? null : (string) $row['connect_mode'],
            self::date($row['connect_started_at']),
            self::date($row['connected_at']),
            $row['phone_display'] === null ? null : (string) $row['phone_display'],
            $row['profile_name'] === null ? null : (string) $row['profile_name'],
            $row['last_error_code'] === null ? null : (string) $row['last_error_code'],
            self::date($row['status_checked_at']),
        );
    }

    public function ensure(InstallationId $installation, string $provider, string $instanceName, int $userId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO whatsapp_connections (installation_id, provider, instance_name, status, created_at, updated_at, updated_by_user_id)
             VALUES (:inst, :provider, :name, \'new\', :at, :at2, :user)'
        )->execute(['inst' => $installation->value, 'provider' => $provider, 'name' => $instanceName, 'at' => self::ts($now), 'at2' => self::ts($now), 'user' => $userId]);
    }

    public function storeInstance(InstallationId $installation, ProviderInstance $instance, int $userId, \DateTimeImmutable $now): void
    {
        $this->update($installation, [
            'instance_name' => $instance->name,
            'provider_instance_id' => $instance->providerId,
            'instance_token_enc' => $this->box->encrypt($instance->token),
            'key_id' => SecretBox::KEY_ID,
            'status' => 'disconnected',
            'last_error_code' => null,
            'updated_by_user_id' => $userId,
        ], $now);
    }

    public function clearInstance(InstallationId $installation, string $newInstanceName, \DateTimeImmutable $now): void
    {
        $this->update($installation, [
            'instance_name' => $newInstanceName,
            'provider_instance_id' => null,
            'instance_token_enc' => null,
            'key_id' => null,
            'status' => 'new',
            'connect_mode' => null,
            'connect_started_at' => null,
            'phone_display' => null,
            'profile_name' => null,
        ], $now);
    }

    public function markConnecting(InstallationId $installation, string $mode, int $userId, \DateTimeImmutable $now): void
    {
        $this->update($installation, [
            'status' => 'connecting',
            'connect_mode' => $mode,
            'connect_started_at' => self::ts($now),
            'last_error_code' => null,
            'last_error_at' => null,
            'updated_by_user_id' => $userId,
        ], $now);
    }

    public function recordSnapshot(InstallationId $installation, ConnectionSnapshot $snapshot, \DateTimeImmutable $now): void
    {
        $values = [
            'status' => $snapshot->state,
            'status_checked_at' => self::ts($now),
            'last_error_code' => null,
            'last_error_at' => null,
        ];
        if ($snapshot->isConnected()) {
            $current = $this->find($installation);
            if ($current !== null && $current->status !== 'connected') {
                $values['connected_at'] = self::ts($now);
            }
            $values['phone_display'] = self::maskPhone($snapshot->phone);
            $values['profile_name'] = $snapshot->profileName;
            $values['connect_mode'] = null;
            $values['connect_started_at'] = null;
        }
        $this->update($installation, $values, $now);
    }

    public function markDisconnected(InstallationId $installation, ?int $userId, \DateTimeImmutable $now): void
    {
        $values = [
            'status' => 'disconnected',
            'connect_mode' => null,
            'connect_started_at' => null,
            'phone_display' => null,
            'profile_name' => null,
            'status_checked_at' => self::ts($now),
            'last_error_code' => null,
            'last_error_at' => null,
        ];
        if ($userId !== null) {
            $values['updated_by_user_id'] = $userId;
        }
        $this->update($installation, $values, $now);
    }

    public function recordError(InstallationId $installation, string $code, \DateTimeImmutable $now): void
    {
        $this->update($installation, ['last_error_code' => mb_substr($code, 0, 32), 'last_error_at' => self::ts($now)], $now);
    }

    /** "+55 (11) •••••-9999": o painel mostra só o final do número conectado. */
    public static function maskPhone(?string $digits): ?string
    {
        if ($digits === null || preg_match('/^\d{8,15}$/', $digits) !== 1) {
            return null;
        }
        $last = substr($digits, -4);

        return str_starts_with($digits, '55') && strlen($digits) >= 12
            ? sprintf('+55 (%s) •••••-%s', substr($digits, 2, 2), $last)
            : '•••• ' . $last;
    }

    /** @param array<string, scalar|null> $values colunas fixas deste repositório (nunca vindas do usuário) */
    private function update(InstallationId $installation, array $values, \DateTimeImmutable $now): void
    {
        $values['updated_at'] = self::ts($now);
        $sets = [];
        $params = ['inst' => $installation->value];
        foreach ($values as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }
        $this->pdo->prepare('UPDATE whatsapp_connections SET ' . implode(', ', $sets) . ' WHERE installation_id = :inst')->execute($params);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
