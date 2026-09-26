<?php

declare(strict_types=1);

namespace Sinergia\Application\Onboarding;

final readonly class OnboardingStep
{
    public function __construct(
        public string $key,
        public string $title,
        public bool $done,
        public string $status,
        public string $todo,
        public string $url,
        public string $action,
    ) {
    }
}
