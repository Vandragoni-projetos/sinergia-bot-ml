<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Web\Middleware\RequireAuthMiddleware;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\Security\PanelCookies;

/** POST /sair (exige sessão e CSRF) */
final class LogoutAction
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = (string) $request->getAttribute(RequireAuthMiddleware::SESSION_TOKEN, '');
        $body = $request->getParsedBody();
        $submitted = is_array($body) ? ($body['_csrf'] ?? null) : null;

        if (!$this->container->get(Csrf::class)->isValid('sess|' . $token, $submitted)) {
            return $response->withStatus(400);
        }

        $this->container->get(AuthService::class)->logout($token);

        return $this->container->get(PanelCookies::class)
            ->clear($response, PanelCookies::SESSION)
            ->withStatus(302)
            ->withHeader('Location', '/entrar');
    }
}
