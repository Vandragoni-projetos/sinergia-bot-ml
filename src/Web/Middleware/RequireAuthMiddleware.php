<?php

declare(strict_types=1);

namespace Sinergia\Web\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Web\Security\PanelCookies;

/**
 * Exige sessão válida. Sem sessão: redireciona para /entrar.
 * Com sessão: anexa à requisição o TenantContext (a ÚNICA fonte de installation_id do painel).
 */
final class RequireAuthMiddleware implements MiddlewareInterface
{
    public const string SESSION_TOKEN = 'panel.session_token';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ResponseFactoryInterface $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = PanelCookies::read($request, PanelCookies::SESSION);
        $context = $this->container->get(AuthService::class)->resolve($token);

        if ($context === null) {
            $response = $this->responses->createResponse(302)->withHeader('Location', '/entrar');

            return $token === null ? $response : $this->container->get(PanelCookies::class)->clear($response, PanelCookies::SESSION);
        }

        return $handler->handle(
            $request->withAttribute(TenantContext::class, $context)->withAttribute(self::SESSION_TOKEN, $token)
        );
    }
}
