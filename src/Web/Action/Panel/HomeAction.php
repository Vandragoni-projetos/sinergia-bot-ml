<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Infrastructure\Persistence\OnboardingRepository;
use Sinergia\Web\Security\PanelCookies;

/** GET / → Fila (bot ativo) ou Primeiros passos (conta ainda não ativada); sem sessão, tela de entrada. */
final class HomeAction
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenant = $this->container->get(AuthService::class)->resolve(PanelCookies::read($request, PanelCookies::SESSION));
        if ($tenant === null) {
            return $response->withStatus(302)->withHeader('Location', '/entrar');
        }
        $active = $this->container->get(OnboardingRepository::class)->facts($tenant->installationId)->botActive;

        return $response->withStatus(302)->withHeader('Location', $active ? '/fila' : '/comecar');
    }
}
