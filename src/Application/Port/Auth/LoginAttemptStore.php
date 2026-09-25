<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Auth;

interface LoginAttemptStore
{
    public function record(string $emailHash, string $ip, bool $succeeded, \DateTimeImmutable $now): void;

    public function failuresForEmail(string $emailHash, \DateTimeImmutable $since): int;

    public function failuresForIp(string $ip, \DateTimeImmutable $since): int;

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int;
}
