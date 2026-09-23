<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Sinergia\Infrastructure\Crypto\SecretBox;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\ConfigException;

final class ConfigTest extends TestCase
{
    public function testAppEnvIsRequired(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('APP_ENV');
        Config::fromArray([]);
    }

    public function testAppEnvMustBeKnown(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['APP_ENV' => 'prod-ish']);
    }

    public function testTestEnvironmentIsSupported(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'test']);

        self::assertTrue($config->isTest());
        self::assertSame('MLB', $config->siteId());
        self::assertSame('default', $config->installationSlug());
        self::assertSame('dev', $config->appVersion());
    }

    public function testMissingDatabaseListsOnlyKeyNames(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'test', 'DB_HOST' => 'db.internal.example', 'DB_PASSWORD' => 'p4ssw0rd-do-not-print']);

        try {
            $config->database();
            self::fail('Deveria exigir DB_DATABASE e DB_USERNAME.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('DB_DATABASE', $e->getMessage());
            self::assertStringContainsString('DB_USERNAME', $e->getMessage());
            self::assertStringNotContainsString('p4ssw0rd-do-not-print', $e->getMessage());
            self::assertStringNotContainsString('db.internal.example', $e->getMessage());
        }
    }

    public function testDatabasePasswordIsSensitive(): void
    {
        $config = Config::fromArray([
            'APP_ENV' => 'test', 'DB_HOST' => 'h', 'DB_DATABASE' => 'd', 'DB_USERNAME' => 'u', 'DB_PASSWORD' => 'p4ss',
        ]);
        $db = $config->database();

        self::assertSame('p4ss', $db->password->reveal());
        self::assertStringNotContainsString('p4ss', print_r($db, true));
        self::assertSame(3306, $db->port);
    }

    public function testOAuthConfigRequirementsAndSecretMasking(): void
    {
        try {
            Config::fromArray(['APP_ENV' => 'test'])->mercadoLivreOAuth();
            self::fail('Deveria exigir credenciais OAuth.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('ML_CLIENT_ID', $e->getMessage());
            self::assertStringContainsString('ML_CLIENT_SECRET', $e->getMessage());
            self::assertStringContainsString('ML_REDIRECT_URI', $e->getMessage());
        }

        $oauth = Config::fromArray([
            'APP_ENV' => 'test',
            'ML_CLIENT_ID' => '1234567890',
            'ML_CLIENT_SECRET' => 'shh-secret',
            'ML_REDIRECT_URI' => 'https://example.test/callback',
        ])->mercadoLivreOAuth();
        self::assertStringNotContainsString('shh-secret', print_r($oauth, true));
        self::assertTrue($oauth->pkceEnabled);
    }

    public function testRedirectUriMustBeHttpsWithoutQuery(): void
    {
        foreach (['http://example.test/cb', 'https://example.test/cb?x=1', 'ftp://x'] as $bad) {
            try {
                Config::fromArray([
                    'APP_ENV' => 'test', 'ML_CLIENT_ID' => '123456', 'ML_CLIENT_SECRET' => 's', 'ML_REDIRECT_URI' => $bad,
                ])->mercadoLivreOAuth();
                self::fail('Deveria rejeitar ' . $bad);
            } catch (ConfigException $e) {
                self::assertStringNotContainsString($bad, $e->getMessage());
            }
        }
    }

    public function testAppKeyValidation(): void
    {
        $key = SecretBox::generateKeyBase64();
        self::assertSame(32, strlen(Config::fromArray(['APP_ENV' => 'test', 'APP_KEY' => $key])->appKey()->reveal()));

        $this->expectException(ConfigException::class);
        Config::fromArray(['APP_ENV' => 'test', 'APP_KEY' => 'short'])->appKey();
    }

    public function testDebugNeverOnInProduction(): void
    {
        self::assertFalse(Config::fromArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'true'])->appDebug());
        self::assertTrue(Config::fromArray(['APP_ENV' => 'local', 'APP_DEBUG' => 'true'])->appDebug());
    }

    public function testMlBaseUrlsAreOfficialAndHttps(): void
    {
        $ml = Config::fromArray(['APP_ENV' => 'test'])->mercadoLivre();

        self::assertSame('https://api.mercadolibre.com', $ml->apiBaseUrl);
        self::assertSame('https://auth.mercadolivre.com.br', $ml->authBaseUrl);
    }
}
