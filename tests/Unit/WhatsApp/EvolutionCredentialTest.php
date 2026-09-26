<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\WhatsApp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Integration\WhatsApp\Evolution\EvolutionCredential;
use Sinergia\Shared\Config\SensitiveValue;

final class EvolutionCredentialTest extends TestCase
{
    private const string TOKEN = 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D';

    public function testComposeAndParseRoundTripKeepTheTokenSecret(): void
    {
        $credential = EvolutionCredential::compose('sbm-1-botml', new SensitiveValue(self::TOKEN));
        self::assertSame('evo1:sbm-1-botml:' . self::TOKEN, $credential->reveal());
        self::assertSame('[REDACTED]', (string) $credential, 'Fora de reveal() a credencial é mascarada.');
        self::assertSame('"[REDACTED]"', json_encode($credential));
        self::assertStringNotContainsString(self::TOKEN, print_r($credential, true));

        $parsed = EvolutionCredential::parse($credential);
        self::assertSame('sbm-1-botml', $parsed->instanceName);
        self::assertSame(self::TOKEN, $parsed->token->reveal());
        self::assertStringNotContainsString(self::TOKEN, print_r($parsed, true));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCredentials(): iterable
    {
        yield 'vazio' => [''];
        yield 'sem prefixo' => ['sbm-1-botml:' . self::TOKEN];
        yield 'prefixo errado' => ['evo2:sbm-1-botml:' . self::TOKEN];
        yield 'token da Uazapi (sem nome)' => [self::TOKEN];
        yield 'nome com maiúscula' => ['evo1:SBM-1:' . self::TOKEN];
        yield 'nome com barra (injeção de rota)' => ['evo1:sbm/../x:' . self::TOKEN];
        yield 'nome curto' => ['evo1:ab:' . self::TOKEN];
        yield 'token com dois-pontos extra' => ['evo1:sbm-1-botml:' . self::TOKEN . ':x'];
        yield 'token curto' => ['evo1:sbm-1-botml:abc'];
        yield 'token com espaço' => ['evo1:sbm-1-botml:' . str_replace('-', ' ', self::TOKEN)];
        yield 'token com quebra de linha' => ['evo1:sbm-1-botml:' . self::TOKEN . "\n"];
    }

    #[DataProvider('invalidCredentials')]
    public function testInvalidCredentialIsRejectedWithoutEchoingIt(string $raw): void
    {
        try {
            EvolutionCredential::parse(new SensitiveValue($raw));
            self::fail('Credencial inválida deveria ser recusada.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Credencial da instância Evolution inválida.', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage() . $e->getTraceAsString());
        }
    }

    public function testComposeValidatesNameAndTokenWithGenericMessages(): void
    {
        foreach (['', 'Sbm-1', 'sbm_1', 'sbm 1', 'sbm/1', str_repeat('a', 65)] as $name) {
            self::assertFalse(EvolutionCredential::isValidName($name), $name);
        }
        self::assertTrue(EvolutionCredential::isValidName('sbm-1-botml'));

        try {
            EvolutionCredential::compose('sbm-1-botml', new SensitiveValue('tem:dois-pontos-no-token'));
            self::fail('Token com ":" quebraria o formato.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('dois-pontos', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        EvolutionCredential::compose('Sbm', new SensitiveValue(self::TOKEN));
    }
}
