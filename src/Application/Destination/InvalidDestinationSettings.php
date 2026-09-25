<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

final class InvalidDestinationSettings extends \InvalidArgumentException
{
    /** @param array<string, string> $errors campo → mensagem exibível */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Configuração de destino inválida: ' . implode(', ', array_keys($errors)) . '.');
    }
}
