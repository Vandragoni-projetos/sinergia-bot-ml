<?php

declare(strict_types=1);

namespace Sinergia\Web\Security;

use Sinergia\Shared\Config\SensitiveValue;

/**
 * Token CSRF sem estado: HMAC-SHA256(APP_KEY, vínculo). O vínculo é o cookie de pré-login
 * (formulário de entrada) ou o token da sessão (formulários do painel). Nada é guardado.
 */
final class Csrf
{
    public function __construct(private readonly SensitiveValue $key)
    {
    }

    public function tokenFor(string $binding): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'csrf|' . $binding, $this->key->reveal(), true)), '+/', '-_'), '=');
    }

    public function isValid(string $binding, mixed $submitted): bool
    {
        return $binding !== '' && is_string($submitted) && hash_equals($this->tokenFor($binding), $submitted);
    }
}
