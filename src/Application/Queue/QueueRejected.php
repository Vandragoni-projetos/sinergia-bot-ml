<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

final class QueueRejected extends \RuntimeException
{
    public const string NOT_FOUND = 'item_not_found';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Operação na fila recusada: ' . $reason . '.');
    }
}
