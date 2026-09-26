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
use Sinergia\Web\Action\Panel\AffiliateBatchAction;
use Sinergia\Web\Action\Panel\ConnectionsPageAction;
use Sinergia\Web\Action\Panel\DestinationAction;
use Sinergia\Web\Action\Panel\DestinationsPageAction;
use Sinergia\Web\Action\Panel\HomeAction;
use Sinergia\Web\Action\Panel\MediaDeclarationAction;
use Sinergia\Web\Action\Panel\MercadoLivreConnectAction;
use Sinergia\Web\Action\Panel\NicheSaveAction;
use Sinergia\Web\Action\Panel\NichesPageAction;
use Sinergia\Web\Action\Panel\OnboardingAction;
use Sinergia\Web\Action\Panel\OnboardingPageAction;
use Sinergia\Web\Action\Panel\LoginPageAction;
use Sinergia\Web\Action\Panel\LoginSubmitAction;
use Sinergia\Web\Action\Panel\LogoutAction;
use Sinergia\Web\Action\Panel\PanelPageAction;
use Sinergia\Web\Action\Panel\QueueAction;
use Sinergia\Web\Action\Panel\QueuePageAction;
use Sinergia\Web\Action\Panel\WhatsAppConnectionAction;
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
                if (!in_array($slug, ['conexoes', 'nichos', 'destinos', 'fila'], true)) {
                    $panel->get('/' . $slug, new PanelPageAction($container, $slug));
                }
            }
            $panel->get('/conexoes', new ConnectionsPageAction($container));
            $panel->post('/conexoes/mercadolivre/conectar', new MercadoLivreConnectAction($container));
            $panel->post('/conexoes/afiliado/declaracao', new MediaDeclarationAction($container));
            $panel->post('/conexoes/whatsapp/conectar', new WhatsAppConnectionAction($container, WhatsAppConnectionAction::CONNECT));
            $panel->post('/conexoes/whatsapp/desconectar', new WhatsAppConnectionAction($container, WhatsAppConnectionAction::DISCONNECT));
            $panel->get('/nichos', new NichesPageAction($container));
            $panel->post('/nichos/{nicho:[a-z0-9-]{1,64}}', new NicheSaveAction($container));
            $panel->get('/destinos', new DestinationsPageAction($container));
            $panel->post('/destinos/sincronizar', new DestinationAction($container, DestinationAction::SYNC));
            $panel->post('/destinos/adicionar', new DestinationAction($container, DestinationAction::ADD));
            foreach (['configurar' => DestinationAction::CONFIGURE, 'pausar' => DestinationAction::PAUSE, 'ativar' => DestinationAction::ACTIVATE,
                'declarar' => DestinationAction::DECLARE, 'remover' => DestinationAction::REMOVE, 'teste' => DestinationAction::TEST] as $path => $operation) {
                $panel->post('/destinos/{chave:[a-f0-9]{20}}/' . $path, new DestinationAction($container, $operation));
            }
            $panel->get('/comecar', new OnboardingPageAction($container));
            $panel->post('/comecar/ativar', new OnboardingAction($container, OnboardingAction::ACTIVATE));
            $panel->post('/comecar/ofertas', new OnboardingAction($container, OnboardingAction::SEARCH));
            $panel->get('/fila', new QueuePageAction($container));
            $panel->post('/fila/links/exportar', new AffiliateBatchAction($container, AffiliateBatchAction::EXPORT));
            foreach (['colar' => AffiliateBatchAction::PASTE, 'confirmar' => AffiliateBatchAction::CONFIRM, 'descartar' => AffiliateBatchAction::CANCEL] as $path => $operation) {
                $panel->post('/fila/links/{lote:[a-f0-9]{20}}/' . $path, new AffiliateBatchAction($container, $operation));
            }
            $panel->post('/fila/bot/pausar', new QueueAction($container, QueueAction::PAUSE));
            $panel->post('/fila/bot/ativar', new QueueAction($container, QueueAction::ACTIVATE));
            $panel->post('/fila/itens/{item:[a-f0-9]{20}}/aprovar', new QueueAction($container, QueueAction::APPROVE));
            $panel->post('/fila/itens/{item:[a-f0-9]{20}}/pular', new QueueAction($container, QueueAction::SKIP));
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
