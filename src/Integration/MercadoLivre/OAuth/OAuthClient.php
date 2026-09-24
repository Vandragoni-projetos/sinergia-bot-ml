<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\OAuth;

use Sinergia\Application\Port\MercadoLivre\AuthorizationCodeExchanger;
use Sinergia\Application\Port\MercadoLivre\TokenSet;
use Sinergia\Integration\MercadoLivre\Exception\HttpErrorException;
use Sinergia\Integration\MercadoLivre\Http\MercadoLivreClient;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\MercadoLivreOAuthConfig;
use Sinergia\Shared\Config\SensitiveValue;

/**
 * Fluxo oficial "Authorization Code (server side)" + PKCE opcional (docs "Autenticação e Autorização").
 * O login no Mercado Livre é SEMPRE feito pelo próprio usuário no navegador;
 * este código nunca vê senha, cookie ou CSRF do Mercado Livre.
 */
final class OAuthClient implements AuthorizationCodeExchanger
{
    public function __construct(
        private readonly MercadoLivreClient $client,
        private readonly MercadoLivreConfig $ml,
        private readonly MercadoLivreOAuthConfig $oauth,
    ) {
    }

    public function authorizationUrl(SensitiveValue $state, ?SensitiveValue $codeVerifier): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->oauth->clientId,
            'redirect_uri' => $this->oauth->redirectUri,
            'state' => $state->reveal(),
        ];
        if ($codeVerifier !== null) {
            $params['code_challenge'] = Pkce::challenge($codeVerifier);
            $params['code_challenge_method'] = 'S256';
        }

        return rtrim($this->ml->authBaseUrl, '/') . '/authorization?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(SensitiveValue $code, ?SensitiveValue $codeVerifier): TokenSet
    {
        $form = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->oauth->clientId,
            'client_secret' => $this->oauth->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->oauth->redirectUri,
        ];
        if ($codeVerifier !== null) {
            $form['code_verifier'] = $codeVerifier;
        }

        return $this->token($form);
    }

    public function refresh(SensitiveValue $refreshToken): TokenSet
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'client_id' => $this->oauth->clientId,
            'client_secret' => $this->oauth->clientSecret,
            'refresh_token' => $refreshToken,
        ]);
    }

    public function clientId(): string
    {
        return $this->oauth->clientId;
    }

    public function pkceEnabled(): bool
    {
        return $this->oauth->pkceEnabled;
    }

    /** @param array<string, string|SensitiveValue> $form */
    private function token(array $form): TokenSet
    {
        try {
            $response = $this->client->postForm('/oauth/token', $form);
        } catch (HttpErrorException $e) {
            // Mensagem já sanitizada; preserva error oficial (ex.: invalid_grant) para diagnóstico.
            throw new OAuthException(
                $e->getMessage(),
                $e->mlError ?? $e->errorCode,
                httpStatus: $e->httpStatus,
                path: '/oauth/token',
                mlError: $e->mlError,
            );
        }

        return self::tokenSetFromJson($response->json);
    }

    /** @param array<array-key, mixed> $json */
    public static function tokenSetFromJson(array $json): TokenSet
    {
        $access = $json['access_token'] ?? null;
        $expiresIn = $json['expires_in'] ?? null;
        if (!is_string($access) || $access === '' || !is_int($expiresIn) || $expiresIn <= 0) {
            throw new OAuthException('Resposta de token fora do contrato (access_token/expires_in).', 'token_contract_violation');
        }
        $refresh = $json['refresh_token'] ?? null;

        return new TokenSet(
            accessToken: new SensitiveValue($access),
            refreshToken: is_string($refresh) && $refresh !== '' ? new SensitiveValue($refresh) : null,
            expiresIn: $expiresIn,
            scope: is_string($json['scope'] ?? null) ? $json['scope'] : null,
            userId: is_int($json['user_id'] ?? null) ? $json['user_id'] : null,
            tokenType: is_string($json['token_type'] ?? null) ? $json['token_type'] : 'bearer',
        );
    }
}
