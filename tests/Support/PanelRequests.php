<?php

declare(strict_types=1);

namespace Sinergia\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Requisições simuladas ao painel (cookies, CSRF, login) para testes HTTP. */
trait PanelRequests
{
    abstract protected function panelApp(): App;

    /** @return string cookie de sessão */
    private function signIn(string $email, string $password): string
    {
        $page = $this->httpGet('/entrar');
        preg_match('/name="_csrf" value="([^"]+)"/', (string) $page->getBody(), $m);
        $response = $this->httpPost('/entrar', ['email' => $email, 'password' => $password, '_csrf' => $m[1] ?? ''], ['sbm_pre' => $this->cookieFrom($page, 'sbm_pre')]);

        return $this->cookieFrom($response, 'sbm_session') ?? throw new \RuntimeException('Login falhou no teste.');
    }

    private function csrfFor(string $session, string $path = '/fila'): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', (string) $this->httpGet($path, ['sbm_session' => $session])->getBody(), $m);

        return $m[1] ?? '';
    }

    /**
     * @param array<string, ?string> $cookies
     * @param array<string, string> $query
     */
    private function httpGet(string $path, array $cookies = [], array $query = []): ResponseInterface
    {
        return $this->panelApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path . ($query === [] ? '' : '?' . http_build_query($query)), ['REMOTE_ADDR' => '200.10.10.10'])
                ->withCookieParams(array_filter($cookies, 'is_string'))
                ->withQueryParams($query)
        );
    }

    /**
     * @param array<string, string> $body
     * @param array<string, ?string> $cookies
     */
    private function httpPost(string $path, array $body, array $cookies = []): ResponseInterface
    {
        return $this->panelApp()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path, ['REMOTE_ADDR' => '200.10.10.10'])
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withCookieParams(array_filter($cookies, 'is_string'))
                ->withParsedBody($body)
        );
    }

    private function cookieFrom(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^' . preg_quote($name, '/') . '=([^;]*)/', $header, $m) === 1 && $m[1] !== '') {
                return rawurldecode($m[1]);
            }
        }

        return null;
    }
}
