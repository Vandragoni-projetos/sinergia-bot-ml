<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Web\Security\PanelCookies;

/** GET / → painel (Fila) se autenticado; senão, tela de entrada. */
final class HomeAction
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $signedIn = $this->container->get(AuthService::class)->resolve(PanelCookies::read($request, PanelCookies::SESSION)) !== null;

        return $response->withStatus(302)->withHeader('Location', $signedIn ? '/fila' : '/entrar');
    }
}
