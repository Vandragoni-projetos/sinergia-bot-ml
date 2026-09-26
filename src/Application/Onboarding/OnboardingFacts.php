<?php

declare(strict_types=1);

namespace Sinergia\Application\Onboarding;

/** Fatos lidos do banco para UMA conta. Nada vem do navegador. */
final readonly class OnboardingFacts
{
    public function __construct(
        public bool $mercadoLivreConnected,
        public bool $mediaDeclared,
        public int $activeSubniches,
        public bool $whatsAppConnected,
        public int $destinations,
        public int $readyDestinations,
        public int $candidates,
        public int $candidatesWithLink,
        public bool $botActive,
        public ?\DateTimeImmutable $lastOfferSearch,
    ) {
    }
}
