<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Niche\InvalidNicheFilters;
use Sinergia\Application\Niche\NicheFilters;

final class NicheFiltersTest extends TestCase
{
    public function testEmptyFormMeansNoLimits(): void
    {
        $filters = NicheFilters::fromForm([]);

        self::assertNull($filters->minDiscountPct);
        self::assertNull($filters->minPriceCents);
        self::assertNull($filters->maxPriceCents);
        self::assertFalse($filters->requirePhoto, 'Checkbox desmarcado não é enviado pelo navegador.');
        self::assertTrue(NicheFilters::defaults()->requirePhoto);
        self::assertTrue(NicheFilters::defaults()->isDefault());
    }

    /** @return iterable<string, array{array<string, string>, ?int, ?int, ?int}> */
    public static function validForms(): iterable
    {
        yield 'inteiros' => [['desconto_minimo' => '15', 'preco_minimo' => '30', 'preco_maximo' => '500'], 15, 3000, 50000];
        yield 'formato brasileiro' => [['preco_minimo' => '1.299,90', 'preco_maximo' => '2.000'], null, 129990, 200000];
        yield 'vírgula e ponto decimal' => [['preco_minimo' => '19,9', 'preco_maximo' => '19.99'], null, 1990, 1999];
        yield 'símbolos' => [['desconto_minimo' => '20%', 'preco_minimo' => 'R$ 45'], 20, 4500, null];
        yield 'zero = sem desconto' => [['desconto_minimo' => '0'], null, null, null];
        yield 'min = max' => [['preco_minimo' => '100', 'preco_maximo' => '100,00'], null, 10000, 10000];
        yield 'limites' => [['desconto_minimo' => '90', 'preco_maximo' => '100.000,00'], 90, null, 10_000_000];
    }

    /** @param array<string, string> $form */
    #[DataProvider('validForms')]
    public function testValidForms(array $form, ?int $discount, ?int $min, ?int $max): void
    {
        $filters = NicheFilters::fromForm($form + ['exige_foto' => '1']);

        self::assertSame($discount, $filters->minDiscountPct);
        self::assertSame($min, $filters->minPriceCents);
        self::assertSame($max, $filters->maxPriceCents);
        self::assertTrue($filters->requirePhoto);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidForms(): iterable
    {
        yield 'desconto acima de 90' => [['desconto_minimo' => '95'], ['desconto_minimo']];
        yield 'desconto negativo' => [['desconto_minimo' => '-5'], ['desconto_minimo']];
        yield 'desconto decimal' => [['desconto_minimo' => '12,5'], ['desconto_minimo']];
        yield 'preço texto' => [['preco_minimo' => 'abc'], ['preco_minimo']];
        yield 'preço com 3 decimais' => [['preco_minimo' => '10,999'], ['preco_minimo']];
        yield 'preço zero' => [['preco_minimo' => '0'], ['preco_minimo']];
        yield 'preço acima do teto' => [['preco_maximo' => '100.000,01'], ['preco_maximo']];
        yield 'máximo menor que mínimo' => [['preco_minimo' => '500', 'preco_maximo' => '30'], ['preco_maximo']];
        yield 'vários campos' => [['desconto_minimo' => 'x', 'preco_minimo' => '1,2,3', 'preco_maximo' => '-1'], ['desconto_minimo', 'preco_minimo', 'preco_maximo']];
        yield 'tipos não string' => [['desconto_minimo' => ['15'], 'preco_minimo' => '30'], []];
    }

    /**
     * @param array<string, mixed> $form
     * @param list<string>         $fields
     */
    #[DataProvider('invalidForms')]
    public function testInvalidForms(array $form, array $fields): void
    {
        if ($fields === []) {
            // Valor não textual é ignorado (tratado como vazio), nunca convertido.
            self::assertNull(NicheFilters::fromForm($form)->minDiscountPct);

            return;
        }
        try {
            NicheFilters::fromForm($form);
            self::fail('Deveria rejeitar.');
        } catch (InvalidNicheFilters $e) {
            $keys = array_keys($e->errors);
            sort($keys);
            sort($fields);
            self::assertSame($fields, $keys);
        }
    }

    public function testConstructorAlsoValidates(): void
    {
        $this->expectException(InvalidNicheFilters::class);
        new NicheFilters(10, 5000, 1000, true);
    }

    public function testFormatCents(): void
    {
        self::assertSame('', NicheFilters::formatCents(null));
        self::assertSame('30', NicheFilters::formatCents(3000));
        self::assertSame('1.299,90', NicheFilters::formatCents(129990));
        self::assertSame('0,05', NicheFilters::formatCents(5));
    }
}
