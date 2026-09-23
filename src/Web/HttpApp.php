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
use Sinergia\Web\Action\HealthAction;

/** Aplicação HTTP (Slim). No MVP 0 expõe apenas /health. */
final class HttpApp
{
    public static function create(ContainerInterface $container): App
    {
        /** @var Config $config */
        $config = $container->get(Config::class);

        AppFactory::setContainer($container);
        $app = AppFactory::create();
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
