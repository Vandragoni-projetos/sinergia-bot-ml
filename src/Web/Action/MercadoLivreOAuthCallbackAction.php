<?php

declare(strict_types=1);

namespace Sinergia\Web\Action;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\AuthService;
use Sinergia\Application\OAuth\CompleteMercadoLivreAuthorization;
use Sinergia\Application\OAuth\OAuthAuthorizationFailed;
use Sinergia\Application\OAuth\OAuthCallbackParameters;
use Sinergia\Web\Security\PanelCookies;

/**
 * redirect_uri do OAuth: o Mercado Livre devolve o navegador aqui com ?code=...&state=...
 *
 * - A conta vem do PRÓPRIO state (gravado ao iniciar), nunca de configuração.
 * - Conexão iniciada no painel: só conclui se a sessão do navegador for da mesma conta;
 *   ao final volta para /conexoes com um código de resultado (nunca code, state ou token).
 * - Conexão iniciada pelo terminal (ml:oauth:start): mantém a página simples de resultado.
 */
final class MercadoLivreOAuthCallbackAction
{
    public const string PATH = '/oauth/mercadolivre/callback';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = OAuthCallbackParameters::fromQuery($request->getQueryParams());
        $tenant = $this->container->has(AuthService::class)
            ? $this->container->get(AuthService::class)->resolve(PanelCookies::read($request, PanelCookies::SESSION))
            : null;

        try {
            $this->container->get(CompleteMercadoLivreAuthorization::class)->completeFromCallback($params, $tenant?->installationId);
        } catch (OAuthAuthorizationFailed $e) {
            if ($tenant !== null) {
                return $response->withStatus(302)->withHeader('Location', '/conexoes?ml=erro&motivo=' . rawurlencode($e->reason));
            }

            return self::page($response, match ($e->reason) {
                OAuthAuthorizationFailed::TOKEN_EXCHANGE_FAILED => 502,
                OAuthAuthorizationFailed::STORAGE_FAILED => 500,
                default => 400,
            }, 'Não foi possível conectar o Mercado Livre.', $e->getMessage(), $e->reason);
        } catch (\Throwable $e) {
            $this->container->get(LoggerInterface::class)->error('oauth.callback_error', ['exception' => $e::class]);
            if ($tenant !== null) {
                return $response->withStatus(302)->withHeader('Location', '/conexoes?ml=erro&motivo=internal_error');
            }

            return self::page($response, 500, 'Não foi possível conectar o Mercado Livre.', 'Erro interno. Nada foi gravado.', 'internal_error');
        }

        if ($tenant !== null) {
            return $response->withStatus(302)->withHeader('Location', '/conexoes?ml=conectado');
        }

        return self::page($response, 200, 'Mercado Livre conectado com sucesso.', 'Você já pode fechar esta página.', null);
    }

    private static function page(ResponseInterface $response, int $status, string $title, string $detail, ?string $reason): ResponseInterface
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $response->getBody()->write(
            '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>SINERGIA BOT ML</title></head><body>'
            . '<h1>' . $e($title) . '</h1><p>' . $e($detail) . '</p>'
            . ($reason === null ? '' : '<p>Código: ' . $e($reason) . '</p>')
            . '</body></html>'
        );

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
