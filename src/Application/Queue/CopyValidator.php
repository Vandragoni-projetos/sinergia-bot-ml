<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Sinergia\Application\Port\Copy\OfferCopy;
use Sinergia\Application\Port\Copy\OfferCopyFailure;
use Sinergia\Application\Port\Copy\OfferCopyRequest;

/**
 * Validação RIGOROSA do texto criativo devolvido pela IA. A copy só pode enfeitar: tudo o que é objetivo (preço,
 * preço anterior, desconto, link, fatos comerciais) continua com o BotML. Na dúvida, recusa — a recusa só faz a
 * oferta sair com a mensagem fixa v1, nunca impede a publicação.
 *
 * Recusa (regra entre parênteses, só o nome da regra vai para o log):
 *  - campo vazio, curto demais ou longo demais (length); mais de uma linha (multiline); caractere de controle (control);
 *  - formatação/marcação: * _ ~ ` < > { } [ ] | \ (markup) — a formatação é do BotML;
 *  - URL, domínio ou "www" (url); @ (handle); símbolo de moeda (currency); % (percent);
 *  - número que não esteja no próprio título (number) — bloqueia preço, desconto, parcelas, telefone, prazos;
 *  - termos comerciais que só o BotML pode afirmar (forbidden_term:<termo>): preço, desconto, Pix, parcelas, cupom,
 *    garantia, estoque, urgência...; "frete/grátis/entrega" só com frete grátis confirmado; "oficial/original" só
 *    com loja oficial confirmada;
 *  - mais de MAX_EMOJIS emojis por campo (emoji).
 */
final class CopyValidator
{
    public const int HOOK_MAX = 100;
    public const int CALL_MAX = 60;
    public const int MIN_LENGTH = 3;
    public const int MAX_EMOJIS = 2;

    /** Termos (sem acento, minúsculos) que a IA nunca pode usar; casam no início de palavra. */
    private const array ALWAYS_FORBIDDEN = [
        'preco', 'valor', 'desconto', 'pix', 'parcel', 'juros', 'a vista', 'avista', 'cupom', 'cupon', 'reais', 'barat',
        'garantia', 'estoque', 'ultim', 'hoje', 'amanha', 'acab', 'esgot', 'limitad', 'relampago', 'cashback', 'brinde',
        'economi', 'metade', 'dobro', 'mercado livre', 'mercadolivre', 'whatsapp', 'telefone', 'ligue',
    ];
    /** Palavras inteiras sempre proibidas. */
    private const array ALWAYS_FORBIDDEN_WORDS = ['off', 'gratuito', 'gratuita'];
    private const array FREE_SHIPPING_TERMS = ['frete', 'gratis', 'entrega'];
    private const array OFFICIAL_STORE_TERMS = ['oficial', 'original'];

    private const array ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

    /** @throws OfferCopyFailure REJECTED, com o nome da regra */
    public function validate(OfferCopy $draft, OfferCopyRequest $request): OfferCopy
    {
        return new OfferCopy(
            $this->field($draft->hook, self::HOOK_MAX, $request),
            $this->field($draft->callToAction, self::CALL_MAX, $request),
        );
    }

    private function field(string $raw, int $max, OfferCopyRequest $request): string
    {
        if (preg_match('/[\r\n\x{2028}\x{2029}]/u', $raw) === 1) {
            self::reject('multiline');
        }
        // UTF-8 inválido ou caractere de controle/formatação invisível (exceto o ZWJ dos emojis compostos).
        if (preg_match('//u', $raw) !== 1 || preg_match('/(?!\x{200D})[\p{Cc}\p{Cf}]/u', $raw) === 1) {
            self::reject('control');
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $length = mb_strlen($text);
        if ($length < self::MIN_LENGTH || $length > $max) {
            self::reject('length');
        }
        if (preg_match('/[*_~`<>{}\[\]|\\\\]/u', $text) === 1) {
            self::reject('markup');
        }
        $normalized = self::normalize($text);
        if (preg_match('#(https?|://|www\.|\b[\p{L}\p{N}-]+\.(com|br|la|net|org|io|me|ly|app|shop|store|link|site|co)\b)#iu', $normalized) === 1) {
            self::reject('url');
        }
        if (str_contains($text, '@')) {
            self::reject('handle');
        }
        if (preg_match('/[$\x{20AC}\x{00A3}\x{00A5}\x{00A2}]/u', $text) === 1) {
            self::reject('currency');
        }
        if (preg_match('/[%\x{FF05}\x{2030}]/u', $text) === 1) {
            self::reject('percent');
        }
        $this->numbersOnlyFromTitle($normalized, $request->title);
        $this->terms($normalized, $request);
        if (preg_match_all('/\p{Extended_Pictographic}/u', $text) > self::MAX_EMOJIS) {
            self::reject('emoji');
        }

        return $text;
    }

    /** Todo "token" com dígito precisa existir, igual, no título (ex.: "4L" em "Air Fryer 4L"). */
    private function numbersOnlyFromTitle(string $normalized, string $title): void
    {
        if (preg_match_all('/[\p{L}\p{N}]*\p{N}[\p{L}\p{N}]*/u', $normalized, $m) === 0) {
            return;
        }
        preg_match_all('/[\p{L}\p{N}]+/u', self::normalize($title), $t);
        $allowed = array_fill_keys($t[0], true);
        foreach ($m[0] as $token) {
            if (!isset($allowed[$token])) {
                self::reject('number');
            }
        }
    }

    private function terms(string $normalized, OfferCopyRequest $request): void
    {
        $prefixes = self::ALWAYS_FORBIDDEN;
        if (!$request->freeShipping) {
            $prefixes = array_merge($prefixes, self::FREE_SHIPPING_TERMS);
        }
        if (!$request->officialStore) {
            $prefixes = array_merge($prefixes, self::OFFICIAL_STORE_TERMS);
        }
        foreach ($prefixes as $term) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '/u', $normalized) === 1) {
                self::reject('forbidden_term:' . $term);
            }
        }
        foreach (self::ALWAYS_FORBIDDEN_WORDS as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $normalized) === 1) {
                self::reject('forbidden_term:' . $word);
            }
        }
    }

    private static function normalize(string $text): string
    {
        return strtr(mb_strtolower($text), self::ACCENTS);
    }

    private static function reject(string $rule): never
    {
        throw new OfferCopyFailure(OfferCopyFailure::REJECTED, null, $rule);
    }
}
