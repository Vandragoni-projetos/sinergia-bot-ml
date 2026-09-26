<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Port\Copy\OfferCopy;
use Sinergia\Application\Port\Copy\OfferCopyFailure;
use Sinergia\Application\Port\Copy\OfferCopyRequest;
use Sinergia\Application\Queue\CopyValidator;

/** Etapa 11B: a IA só enfeita; qualquer dado objetivo ou afirmação comercial não confirmada é recusado. */
final class CopyValidatorTest extends TestCase
{
    private function request(bool $freeShipping = false, bool $officialStore = false, string $title = 'Air Fryer Mondial 4L Preta'): OfferCopyRequest
    {
        return new OfferCopyRequest($title, 'Casa e Cozinha', 'Air fryers', $freeShipping, $officialStore);
    }

    public function testAcceptsShortCreativeCopyAndNormalizesSpaces(): void
    {
        $copy = (new CopyValidator())->validate(new OfferCopy('  Crocância   sem óleo na sua cozinha 🍟 ', 'Confira no link 👇'), $this->request());

        self::assertSame('Crocância sem óleo na sua cozinha 🍟', $copy->hook);
        self::assertSame('Confira no link 👇', $copy->callToAction);
    }

    public function testNumbersFromTheTitleAreAllowed(): void
    {
        $copy = (new CopyValidator())->validate(new OfferCopy('Os 4L que sua cozinha pedia', 'Garanta a sua'), $this->request());

        self::assertSame('Os 4L que sua cozinha pedia', $copy->hook);
    }

    public function testConfirmedFactsUnlockTheirWords(): void
    {
        $validator = new CopyValidator();
        $copy = $validator->validate(new OfferCopy('Com frete grátis e loja oficial', 'Aproveite'), $this->request(freeShipping: true, officialStore: true));
        self::assertSame('Com frete grátis e loja oficial', $copy->hook);

        $this->expectRule('forbidden_term:frete');
        $validator->validate(new OfferCopy('Com frete grátis', 'Aproveite'), $this->request(freeShipping: false, officialStore: true));
    }

    public function testOfficialStoreClaimNeedsConfirmation(): void
    {
        $this->expectRule('forbidden_term:oficial');
        (new CopyValidator())->validate(new OfferCopy('Direto da loja oficial', 'Aproveite'), $this->request(freeShipping: true));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejected(): iterable
    {
        yield 'preço em número' => ['Sai por 199 hoje mesmo', 'Aproveite', 'number'];
        yield 'moeda' => ['Menos de R$ 200', 'Aproveite', 'currency'];
        yield 'moeda sem R' => ['Só $ 199', 'Aproveite', 'currency'];
        yield 'porcentagem' => ['Um achado com 30% a menos', 'Aproveite', 'percent'];
        yield 'número fora do título' => ['Mais de mil vendidos em 24h', 'Aproveite', 'number'];
        yield 'telefone' => ['Chame no 11987654321', 'Aproveite', 'number'];
        yield 'link' => ['Veja em https://meli.la/abc', 'Aproveite', 'url'];
        yield 'domínio sem http' => ['Achado no meli.la agora', 'Aproveite', 'url'];
        yield 'www' => ['Confira www.exemplo', 'Aproveite', 'url'];
        yield 'arroba' => ['Siga @achados', 'Aproveite', 'handle'];
        yield 'negrito' => ['*Oferta* imperdível', 'Aproveite', 'markup'];
        yield 'tachado' => ['~De antes~ agora', 'Aproveite', 'markup'];
        yield 'markdown link' => ['[clique](x)', 'Aproveite', 'markup'];
        yield 'multilinha' => ["Linha um\nLinha dois", 'Aproveite', 'multiline'];
        yield 'controle invisível' => ["Achado\u{200B} escondido", 'Aproveite', 'control'];
        yield 'curto demais' => ['Oi', 'Aproveite', 'length'];
        yield 'vazio' => ['   ', 'Aproveite', 'length'];
        yield 'longo demais' => [str_repeat('Achado incrível ', 8), 'Aproveite', 'length'];
        yield 'chamada longa' => ['Achado incrível', str_repeat('Confira agora ', 6), 'length'];
        yield 'emojis demais' => ['Achado 🔥🔥🔥', 'Aproveite', 'emoji'];
        yield 'palavra preço' => ['O melhor preço da semana', 'Aproveite', 'forbidden_term:preco'];
        yield 'desconto' => ['Desconto imperdível', 'Aproveite', 'forbidden_term:desconto'];
        yield 'OFF' => ['Tudo OFF', 'Aproveite', 'forbidden_term:off'];
        yield 'pix' => ['Melhor no Pix', 'Aproveite', 'forbidden_term:pix'];
        yield 'parcelas' => ['Dá para parcelar', 'Aproveite', 'forbidden_term:parcel'];
        yield 'cupom' => ['Use o cupom', 'Aproveite', 'forbidden_term:cupom'];
        yield 'barato' => ['Baratíssimo demais', 'Aproveite', 'forbidden_term:barat'];
        yield 'garantia' => ['Com garantia estendida', 'Aproveite', 'forbidden_term:garantia'];
        yield 'urgência hoje' => ['Só hoje', 'Aproveite', 'forbidden_term:hoje'];
        yield 'últimas unidades' => ['Últimas unidades', 'Aproveite', 'forbidden_term:ultim'];
        yield 'estoque' => ['Estoque voando', 'Aproveite', 'forbidden_term:estoque'];
        yield 'grátis sem frete confirmado' => ['Brinde grátis', 'Aproveite', 'forbidden_term:brinde'];
        yield 'gratuito' => ['Entrega gratuita', 'Aproveite', 'forbidden_term:entrega'];
        yield 'relâmpago' => ['Oferta relâmpago', 'Aproveite', 'forbidden_term:relampago'];
        yield 'na chamada também' => ['Achado incrível', 'Compre por 99', 'number'];
    }

    #[DataProvider('rejected')]
    public function testRejects(string $hook, string $call, string $rule): void
    {
        $this->expectRule($rule);
        (new CopyValidator())->validate(new OfferCopy($hook, $call), $this->request());
    }

    public function testRejectionNeverCarriesTheText(): void
    {
        try {
            (new CopyValidator())->validate(new OfferCopy('Sai por 199 no link secreto', 'Aproveite'), $this->request());
            self::fail('Deveria recusar.');
        } catch (OfferCopyFailure $e) {
            self::assertStringNotContainsString('199', $e->getMessage());
            self::assertStringNotContainsString('secreto', $e->getMessage());
        }
    }

    private function expectRule(string $rule): void
    {
        $this->expectException(OfferCopyFailure::class);
        $this->expectExceptionMessage('rejected (' . $rule . ')');
    }
}
