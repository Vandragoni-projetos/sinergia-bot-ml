<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Sinergia\Application\Port\WhatsApp\WhatsAppProvider;
use Sinergia\Integration\WhatsApp\Evolution\EvolutionProvider;
use Sinergia\Integration\WhatsApp\Uazapi\UazapiProvider;
use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\ConfigException;

/** Escolha do provedor de WhatsApp: Uazapi continua o padrão; Evolution (opção C) só com URL, nunca chave global. */
final class WhatsAppProviderConfigTest extends TestCase
{
    public function testUazapiRemainsTheDefaultAndStillResolves(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'test', 'UAZAPI_BASE_URL' => 'https://uazapi.example.test', 'UAZAPI_ADMIN_TOKEN' => 'TESTE-admin']);
        self::assertSame('uazapi', $config->whatsAppProvider());
        self::assertTrue($config->hasWhatsApp());
        $container = Kernel::container($config, dirname(__DIR__, 3));
        self::assertInstanceOf(UazapiProvider::class, $container->get(WhatsAppProvider::class));
    }

    public function testEvolutionNeedsOnlyTheBaseUrl(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'test', 'WHATSAPP_PROVIDER' => 'evolution', 'EVOLUTION_BASE_URL' => 'https://evolution.example.test/']);
        self::assertTrue($config->hasWhatsApp());
        self::assertSame('https://evolution.example.test', $config->evolution()->baseUrl);
        self::assertSame(15, $config->evolution()->timeoutSeconds);
        $container = Kernel::container($config, dirname(__DIR__, 3));
        self::assertInstanceOf(EvolutionProvider::class, $container->get(WhatsAppProvider::class));

        // Uazapi configurada não conta quando o provedor escolhido é a Evolution.
        $withoutUrl = Config::fromArray(['APP_ENV' => 'test', 'WHATSAPP_PROVIDER' => 'evolution', 'UAZAPI_BASE_URL' => 'https://u.test', 'UAZAPI_ADMIN_TOKEN' => 'x']);
        self::assertFalse($withoutUrl->hasWhatsApp());
    }

    public function testInvalidProviderAndUnsafeUrlsAreRejectedNamingOnlyTheVariable(): void
    {
        foreach (['http://evolution.example.test', 'https://evolution.example.test?apikey=x', 'https://user:pass@evolution.example.test', 'evolution.example.test'] as $url) {
            try {
                Config::fromArray(['APP_ENV' => 'test', 'WHATSAPP_PROVIDER' => 'evolution', 'EVOLUTION_BASE_URL' => $url])->evolution();
                self::fail($url . ' deveria ser recusada.');
            } catch (ConfigException $e) {
                self::assertStringContainsString('EVOLUTION_BASE_URL', $e->getMessage());
                self::assertStringNotContainsString('pass', $e->getMessage());
            }
        }
        $this->expectException(ConfigException::class);
        Config::fromArray(['APP_ENV' => 'test', 'WHATSAPP_PROVIDER' => 'waha'])->whatsAppProvider();
    }

    public function testGlobalEvolutionKeyIsNeverReadByProductionCode(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $code = (string) file_get_contents((string) $file);
            foreach (['EVOLUTION_API_KEY', 'AUTHENTICATION_API_KEY', 'EVOLUTION_GLOBAL'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $code, (string) $file);
            }
        }
    }
}
