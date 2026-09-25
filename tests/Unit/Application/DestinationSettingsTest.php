<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Destination\DestinationSettings;
use Sinergia\Application\Destination\InvalidDestinationSettings;

final class DestinationSettingsTest extends TestCase
{
    private const array VALID = ['nicho' => 'casa-cozinha', 'subnichos' => ['air-fryers', 'panelas', 'air-fryers'], 'inicio' => '09:00', 'fim' => '21:30', 'intervalo' => '45', 'modo' => 'auto'];

    public function testValid(): void
    {
        $settings = DestinationSettings::fromForm(self::VALID);

        self::assertSame('casa-cozinha', $settings->nicheSlug);
        self::assertSame(['air-fryers', 'panelas'], $settings->subnicheSlugs);
        self::assertSame(['09:00', '21:30', 45, 'auto'], [$settings->windowStart, $settings->windowEnd, $settings->intervalMinutes, $settings->mode]);
        self::assertSame('manual', DestinationSettings::fromForm(['modo' => 'manual'] + self::VALID)->mode);
        self::assertSame(10, DestinationSettings::fromForm(['intervalo' => '10'] + self::VALID)->intervalMinutes);
        self::assertSame(720, DestinationSettings::fromForm(['inicio' => '00:00', 'fim' => '23:59', 'intervalo' => '720'] + self::VALID)->intervalMinutes);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'fim antes do início' => [['inicio' => '22:00', 'fim' => '08:00'], 'janela'];
        yield 'início = fim' => [['inicio' => '10:00', 'fim' => '10:00'], 'janela'];
        yield 'janela curta' => [['inicio' => '10:00', 'fim' => '10:20', 'intervalo' => '10'], 'janela'];
        yield 'hora inválida' => [['inicio' => '25:00'], 'janela'];
        yield 'formato inválido' => [['fim' => '9h'], 'janela'];
        yield 'intervalo baixo' => [['intervalo' => '5'], 'intervalo'];
        yield 'intervalo alto' => [['intervalo' => '800'], 'intervalo'];
        yield 'intervalo texto' => [['intervalo' => 'rápido'], 'intervalo'];
        yield 'intervalo maior que a janela' => [['inicio' => '10:00', 'fim' => '11:00', 'intervalo' => '90'], 'intervalo'];
        yield 'modo inválido' => [['modo' => 'turbo'], 'modo'];
        yield 'sem nicho' => [['nicho' => ''], 'nicho'];
        yield 'sem subnicho' => [['subnichos' => []], 'subnichos'];
        yield 'subnichos não lista' => [['subnichos' => 'air-fryers'], 'subnichos'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalid')]
    public function testInvalid(array $override, string $field): void
    {
        try {
            DestinationSettings::fromForm($override + self::VALID);
            self::fail('Deveria rejeitar.');
        } catch (InvalidDestinationSettings $e) {
            self::assertArrayHasKey($field, $e->errors);
        }
    }
}
