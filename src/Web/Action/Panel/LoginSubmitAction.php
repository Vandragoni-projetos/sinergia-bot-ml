<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Application\Auth\LoginResult;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Web\Security\ClientIp;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\Security\PanelCookies;

/** POST /entrar */
final class LoginSubmitAction
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $email = is_string($body['email'] ?? null) ? mb_substr(trim($body['email']), 0, 190) : '';
        $password = new SensitiveValue(is_string($body['password'] ?? null) ? mb_substr($body['password'], 0, 256) : '');
        $pre = PanelCookies::read($request, PanelCookies::PRE_LOGIN);

        if ($pre === null || !$this->container->get(Csrf::class)->isValid('pre|' . $pre, $body['_csrf'] ?? null)) {
            return LoginPageAction::renderForm($this->container, $request, $response, 'A página de entrada expirou. Tente de novo.', $email, 400);
        }

        $result = $this->container->get(AuthService::class)->login(
            $email,
            $password,
            ClientIp::from($request),
            $request->getHeaderLine('User-Agent') ?: null,
        );

        if ($result->outcome === LoginResult::THROTTLED) {
            return LoginPageAction::renderForm($this->container, $request, $response, 'Muitas tentativas. Aguarde alguns minutos e tente de novo.', $email, 429);
        }
        if (!$result->succeeded() || $result->sessionToken === null) {
            return LoginPageAction::renderForm($this->container, $request, $response, 'E-mail ou senha incorretos.', $email, 401);
        }

        $cookies = $this->container->get(PanelCookies::class);
        $response = $cookies->set($response, PanelCookies::SESSION, $result->sessionToken->reveal(), PanelCookies::SESSION_MAX_AGE);
        $response = $cookies->clear($response, PanelCookies::PRE_LOGIN);

        return $response->withStatus(302)->withHeader('Location', '/fila');
    }
}
