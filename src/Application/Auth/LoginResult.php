<?php

declare(strict_types=1);

namespace Sinergia\Application\Auth;

use Sinergia\Shared\Config\SensitiveValue;

final readonly class LoginResult
{
    public const string OK = 'ok';
    public const string INVALID = 'invalid';
    public const string THROTTLED = 'throttled';

    private function __construct(
        public string $outcome,
        public ?SensitiveValue $sessionToken = null,
        public ?TenantContext $context = null,
    ) {
    }

    public static function ok(SensitiveValue $token, TenantContext $context): self
    {
        return new self(self::OK, $token, $context);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function throttled(): self
    {
        return new self(self::THROTTLED);
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::OK;
    }
}
