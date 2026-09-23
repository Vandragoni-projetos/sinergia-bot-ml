<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\MercadoLivre;

use PHPUnit\Framework\TestCase;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthClient;
use Sinergia\Integration\MercadoLivre\OAuth\OAuthException;
use Sinergia\Integration\MercadoLivre\OAuth\Pkce;
use Sinergia\Shared\Config\MercadoLivreConfig;
use Sinergia\Shared\Config\MercadoLivreOAuthConfig;
use Sinergia\Shared\Config\SensitiveValue;
use Sinergia\Tests\Support\FakeMercadoLivre;

final class OAuthTest extends TestCase
{
    private const string SECRET = 'fake-client-secret-for-tests-only';

    public function testPkceS256MatchesRfc7636Vector(): void
    {
        // RFC 7636, Apêndice B.
        $verifier = new SensitiveValue('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge($verifier));
    }

    public function testGeneratedVerifierLengthWithinRfcLimits(): void
    {
        $length = strlen(Pkce::generateVerifier()->reveal());

        self::assertGreaterThanOrEqual(43, $length);
        self::assertLessThanOrEqual(128, $length);
    }

    public function testAuthorizationUrlFollowsOfficialFormat(): void
    {
        $client = $this->oauth(new FakeMercadoLivre(withToken: false));
        $url = $client->authorizationUrl(new SensitiveValue('st4te'), new SensitiveValue('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));

        $parts = parse_url($url);
        parse_str((string) $parts['query'], $query);
        self::assertSame('https', $parts['scheme']);
        self::assertSame('auth.mercadolivre.com.br', $parts['host']);
        self::assertSame('/authorization', $parts['path']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('1234567890123456', $query['client_id']);
        self::assertSame('https://app.example.test/oauth/callback', $query['redirect_uri']);
        self::assertSame('st4te', $query['state']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $query['code_challenge']);
        self::assertStringNotContainsString(self::SECRET, $url, 'client_secret nunca vai para a URL');
    }

    public function testExchangeCodePostsOfficialFormAndParsesTokens(): void
    {
        $fake = new FakeMercadoLivre(withToken: false);
        $fake->queueJson(200, [
            'access_token' => FakeMercadoLivre::TOKEN,
            'token_type' => 'bearer',
            'expires_in' => 21600,
            'scope' => 'offline_access read',
            'user_id' => 1234567,
            'refresh_token' => 'TG-5b9032b4e23464aed1f959f-1234567',
        ]);

        $tokens = $this->oauth($fake)->exchangeCode(new SensitiveValue('TG-code-123'), new SensitiveValue('verifier-abc'));

        $request = $fake->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.mercadolibre.com/oauth/token', (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $form);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('TG-code-123', $form['code']);
        self::assertSame('verifier-abc', $form['code_verifier']);
        self::assertSame(self::SECRET, $form['client_secret']);
        self::assertFalse($request->hasHeader('Authorization'));

        self::assertSame(FakeMercadoLivre::TOKEN, $tokens->accessToken->reveal());
        self::assertSame(21600, $tokens->expiresIn);
        self::assertSame(1234567, $tokens->userId);
        self::assertNotNull($tokens->refreshToken);
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, print_r($tokens, true));
        self::assertStringNotContainsString(FakeMercadoLivre::TOKEN, $fake->logText());
    }

    public function testInvalidGrantIsSanitized(): void
    {
        $fake = new FakeMercadoLivre(withToken: false);
        $fake->queueJson(400, [
            'error' => 'invalid_grant',
            'error_description' => 'Error validating grant.',
            'message' => 'code TG-code-123 invalid',
            'status' => 400,
        ]);

        try {
            $this->oauth($fake)->exchangeCode(new SensitiveValue('TG-code-123'), null);
            self::fail('Deveria falhar.');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->errorCode);
            self::assertStringNotContainsString('TG-code-123', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        self::assertStringNotContainsString(self::SECRET, $fake->logText());
    }

    public function testTokenResponseWithoutAccessTokenIsRejected(): void
    {
        $this->expectException(OAuthException::class);
        OAuthClient::tokenSetFromJson(['expires_in' => 21600]);
    }

    private function oauth(FakeMercadoLivre $fake): OAuthClient
    {
        return new OAuthClient(
            $fake->client,
            new MercadoLivreConfig('MLB', 'https://api.mercadolibre.com', 'https://auth.mercadolivre.com.br', 5, 'test'),
            new MercadoLivreOAuthConfig('1234567890123456', new SensitiveValue(self::SECRET), 'https://app.example.test/oauth/callback', true),
        );
    }
}
