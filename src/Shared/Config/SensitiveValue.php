<?php

declare(strict_types=1);

namespace Sinergia\Shared\Config;

/**
 * Envolve um segredo para que ele nunca apareça por acidente em logs,
 * mensagens de erro, var_dump, print_r, json_encode ou serialização.
 * O valor real só sai por reveal(), que deve ser chamado apenas no ponto de uso.
 */
final class SensitiveValue implements \JsonSerializable, \Stringable
{
    private const string MASK = '[REDACTED]';

    public function __construct(
        #[\SensitiveParameter] private readonly string $value,
    ) {
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    /** @return array<string, string> */
    public function __serialize(): array
    {
        throw new \LogicException('SensitiveValue não pode ser serializado.');
    }

    public static function __set_state(array $properties): never
    {
        throw new \LogicException('SensitiveValue não pode ser exportado.');
    }
}
