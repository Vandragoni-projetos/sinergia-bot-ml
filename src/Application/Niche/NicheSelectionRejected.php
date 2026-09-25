<?php

declare(strict_types=1);

namespace Sinergia\Application\Niche;

/** Escolha fora do catálogo oferecido (nicho/subnicho inexistente, inativo ou sem categoria aprovada). */
final class NicheSelectionRejected extends \RuntimeException
{
    public const string UNKNOWN_NICHE = 'unknown_niche';
    public const string UNKNOWN_SUBNICHE = 'unknown_subniche';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Escolha de nicho rejeitada: ' . $reason . '.');
    }
}
