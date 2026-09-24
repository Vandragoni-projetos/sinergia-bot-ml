<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Sinergia\Application\OAuth\OAuthCallbackParameters;

final class OAuthCallbackParametersTest extends TestCase
{
    private const string URL = 'https://app.example.test/oauth/mercadolivre/callback?code=TG-fake-code&state=fake-state';

    public function testReadsCodeAndStateFromQuery(): void
    {
        $params = OAuthCallbackParameters::fromQuery(['code' => 'TG-fake-code', 'state' => 'fake-state']);

        self::assertSame('TG-fake-code', $params->code?->reveal());
        self::assertSame('fake-state', $params->state?->reveal());
        self::assertNull($params->error);
    }

    public function testMissingOrEmptyValuesBecomeNull(): void
    {
        $params = OAuthCallbackParameters::fromQuery(['code' => '', 'state' => ['array']]);

        self::assertNull($params->code);
        self::assertNull($params->state);
    }

    public function testProviderErrorIsReducedToSafeIdentifier(): void
    {
        self::assertSame('access_denied', OAuthCallbackParameters::fromQuery(['error' => 'access_denied'])->error);
        self::assertSame('unknown_error', OAuthCallbackParameters::fromQuery(['error' => '<script>x</script>'])->error);
    }

    public function testCallbackUrlSurvivesBracketedPasteAndLineBreaks(): void
    {
        foreach ([
            self::URL,
            "\e[200~" . self::URL . "\e[201~",
            '^[[200~' . self::URL . '^[[201~',
            "  " . self::URL . "\r\n",
        ] as $pasted) {
            $params = OAuthCallbackParameters::fromCallbackUrl($pasted);
            self::assertSame('TG-fake-code', $params->code?->reveal());
            self::assertSame('fake-state', $params->state?->reveal(), 'state sem marcadores do terminal');
        }
    }

    public function testValuesAreNotExposedWhenDumped(): void
    {
        $dump = print_r(OAuthCallbackParameters::fromCallbackUrl(self::URL), true);

        self::assertStringNotContainsString('TG-fake-code', $dump);
        self::assertStringNotContainsString('fake-state', $dump);
    }
}
