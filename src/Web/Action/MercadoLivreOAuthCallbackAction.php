<?php

declare(strict_types=1);

namespace Sinergia\Web\Action;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sinergia\Application\OAuth\CompleteMercadoLivreAuthorization;
use Sinergia\Application\OAuth\OAuthAuthorizationFailed;
use Sinergia\Application\OAuth\OAuthCallbackParameters;
use Sinergia\Domain\Installation\Installation;

/**
 * redirect_uri do OAuth: o Mercado Livre devolve o navegador aqui com ?code=...&state=...
 * O code é trocado no servidor e a página só diz se deu certo — nunca ecoa code, state,
 * tokens ou detalhes internos. Serviços são resolvidos só na requisição (o /health não depende deles).
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

        try {
            /** @var Installation $installation */
            $installation = $this->container->get(Installation::class);
            $this->container->get(CompleteMercadoLivreAuthorization::class)->complete($installation->id, $params);
        } catch (OAuthAuthorizationFailed $e) {
            return self::page($response, match ($e->reason) {
                OAuthAuthorizationFailed::TOKEN_EXCHANGE_FAILED => 502,
                OAuthAuthorizationFailed::STORAGE_FAILED => 500,
                default => 400,
            }, 'Não foi possível conectar o Mercado Livre.', $e->getMessage(), $e->reason);
        } catch (\Throwable $e) {
            $this->container->get(LoggerInterface::class)->error('oauth.callback_error', ['exception' => $e::class]);

            return self::page($response, 500, 'Não foi possível conectar o Mercado Livre.', 'Erro interno. Nada foi gravado.', 'internal_error');
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
