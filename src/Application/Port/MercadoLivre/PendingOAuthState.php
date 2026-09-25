<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Config\SensitiveValue;

/** State OAuth consumido: a qual conta pertence, de onde veio e o code_verifier PKCE. */
final readonly class PendingOAuthState
{
    public const string ORIGIN_CLI = 'cli';
    public const string ORIGIN_PANEL = 'panel';

    public function __construct(
        public InstallationId $installationId,
        public string $origin,
        public ?int $startedByUserId,
        public ?SensitiveValue $verifier,
    ) {
    }
}
