<?php

declare(strict_types=1);

namespace Sinergia\Application\Niche;

/** Filtros de nicho inválidos; as mensagens são por campo do formulário e podem ser exibidas. */
final class InvalidNicheFilters extends \InvalidArgumentException
{
    /** @param array<string, string> $errors campo → mensagem */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Filtros de nicho inválidos: ' . implode(', ', array_keys($errors)) . '.');
    }
}
