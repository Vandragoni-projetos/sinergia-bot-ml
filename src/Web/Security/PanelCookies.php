<?php

declare(strict_types=1);

namespace Sinergia\Web\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Cookies do painel: sempre HttpOnly, SameSite=Lax, Path=/ e, fora de local/test, Secure. */
final class PanelCookies
{
    public const string SESSION = 'sbm_session';
    public const string PRE_LOGIN = 'sbm_pre';
    public const int SESSION_MAX_AGE = 604800; // 7 dias (a validade real é controlada no servidor)

    public function __construct(private readonly bool $secure)
    {
    }

    public function set(ResponseInterface $response, string $name, string $value, ?int $maxAge): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->build($name, $value, $maxAge));
    }

    public function clear(ResponseInterface $response, string $name): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->build($name, '', 0));
    }

    public static function read(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getCookieParams()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function build(string $name, string $value, ?int $maxAge): string
    {
        $parts = [$name . '=' . rawurlencode($value), 'Path=/', 'HttpOnly', 'SameSite=Lax'];
        if ($maxAge !== null) {
            $parts[] = 'Max-Age=' . $maxAge;
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
