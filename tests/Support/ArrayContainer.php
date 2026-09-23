<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Container mínimo para testes: só entrega o que foi registrado explicitamente.
 * Se o código pedir algo não registrado (ex.: o cliente OAuth), o teste falha.
 */
final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private array $entries)
    {
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new class ('Serviço não registrado no teste: ' . $id) extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
