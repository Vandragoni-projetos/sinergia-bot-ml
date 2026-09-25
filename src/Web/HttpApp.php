<?php

declare(strict_types=1);

namespace Sinergia\Web;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Sinergia\Shared\Config\Config;
use Slim\Routing\RouteCollectorProxy;
use Sinergia\Web\Action\HealthAction;
use Sinergia\Web\Action\MercadoLivreOAuthCallbackAction;
use Sinergia\Web\Action\Panel\HomeAction;
use Sinergia\Web\Action\Panel\LoginPageAction;
use Sinergia\Web\Action\Panel\LoginSubmitAction;
use Sinergia\Web\Action\Panel\LogoutAction;
use Sinergia\Web\Action\Panel\PanelPageAction;
use Sinergia\Web\Middleware\RequireAuthMiddleware;

/** Aplicação HTTP (Slim): /health, callback OAuth do Mercado Livre e painel. */
final class HttpApp
{
    public static function create(ContainerInterface $container): App
    {
        /** @var Config $config */
        $config = $container->get(Config::class);

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new SecurityHeadersMiddleware());

        $errors = $app->addErrorMiddleware(
            displayErrorDetails: $config->appDebug(),
            logErrors: false,
            logErrorDetails: false,
        );
        $errors->setDefaultErrorHandler(new JsonErrorHandler(
            $app->getResponseFactory(),
            $container->get(LoggerInterface::class),
            $config->appDebug(),
        ));

        $app->get('/health', new HealthAction($config));
        // Precisa coincidir com o caminho de ML_REDIRECT_URI cadastrado no aplicativo do Mercado Livre.
        $app->get(MercadoLivreOAuthCallbackAction::PATH, new MercadoLivreOAuthCallbackAction($container));

        // Painel: entrada pública; demais áreas exigem sessão (TenantContext).
        $app->get('/', new HomeAction($container));
        $app->get('/entrar', new LoginPageAction($container));
        $app->post('/entrar', new LoginSubmitAction($container));
        $app->group('', function (RouteCollectorProxy $panel) use ($container): void {
            foreach (array_keys(PanelPageAction::PAGES) as $slug) {
                $panel->get('/' . $slug, new PanelPageAction($container, $slug));
            }
            $panel->post('/sair', new LogoutAction($container));
        })->add(new RequireAuthMiddleware($container, $app->getResponseFactory()));

        return $app;
    }

    public static function jsonResponse(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function requestPath(ServerRequestInterface $request): string
    {
        return $request->getUri()->getPath();
    }
}
