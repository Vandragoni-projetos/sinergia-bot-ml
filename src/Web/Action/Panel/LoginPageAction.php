<?php

declare(strict_types=1);

namespace Sinergia\Web\Action\Panel;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Web\Security\Csrf;
use Sinergia\Web\Security\PanelCookies;
use Sinergia\Web\View\Views;

/** GET /entrar */
final class LoginPageAction
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->container->get(AuthService::class)->resolve(PanelCookies::read($request, PanelCookies::SESSION)) !== null) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        return self::renderForm($this->container, $request, $response, null, '', 200);
    }

    /** Formulário de entrada com token CSRF vinculado a um cookie de pré-login. */
    public static function renderForm(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $error,
        string $email,
        int $status,
    ): ResponseInterface {
        $pre = PanelCookies::read($request, PanelCookies::PRE_LOGIN);
        if ($pre === null || preg_match('/^[a-f0-9]{32}$/', $pre) !== 1) {
            $pre = bin2hex(random_bytes(16));
            $response = $container->get(PanelCookies::class)->set($response, PanelCookies::PRE_LOGIN, $pre, 3600);
        }

        return $container->get(Views::class)->render($response, 'login.twig', [
            'csrf' => $container->get(Csrf::class)->tokenFor('pre|' . $pre),
            'error' => $error,
            'email' => $email,
        ], $status);
    }
}
