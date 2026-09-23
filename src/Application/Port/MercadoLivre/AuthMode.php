<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

enum AuthMode: string
{
    /** Chamada sem Authorization (verifica se o recurso é público). */
    case None = 'none';

    /** Exige token OAuth; falha antes da chamada se não houver token. */
    case Required = 'oauth';
}
