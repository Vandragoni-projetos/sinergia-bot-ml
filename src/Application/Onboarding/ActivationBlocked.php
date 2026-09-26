<?php

declare(strict_types=1);

namespace Sinergia\Application\Onboarding;

final class ActivationBlocked extends \RuntimeException
{
    public function __construct(public readonly string $pendingStep)
    {
        parent::__construct('Ativação bloqueada: passo pendente ' . $pendingStep . '.');
    }
}
