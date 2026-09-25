<?php

declare(strict_types=1);

namespace Sinergia\Application\Niche;

/**
 * Filtros que a conta aplica aos produtos de um nicho (usados pelo seletor da etapa 4).
 * Preços em centavos; null = sem limite. A validação acontece na construção.
 */
final readonly class NicheFilters
{
    public const int MAX_DISCOUNT_PCT = 90;
    public const int MAX_PRICE_CENTS = 10_000_000;

    /** @throws InvalidNicheFilters */
    public function __construct(
        public ?int $minDiscountPct,
        public ?int $minPriceCents,
        public ?int $maxPriceCents,
        public bool $requirePhoto,
    ) {
        $errors = [];
        if ($minDiscountPct !== null && ($minDiscountPct < 1 || $minDiscountPct > self::MAX_DISCOUNT_PCT)) {
            $errors['desconto_minimo'] = 'Use um número inteiro de 1 a ' . self::MAX_DISCOUNT_PCT . '.';
        }
        foreach (['preco_minimo' => $minPriceCents, 'preco_maximo' => $maxPriceCents] as $field => $cents) {
            if ($cents !== null && ($cents < 1 || $cents > self::MAX_PRICE_CENTS)) {
                $errors[$field] = 'Use um valor entre R$ 0,01 e R$ 100.000,00.';
            }
        }
        if ($errors === [] && $minPriceCents !== null && $maxPriceCents !== null && $maxPriceCents < $minPriceCents) {
            $errors['preco_maximo'] = 'O preço máximo precisa ser maior ou igual ao mínimo.';
        }
        if ($errors !== []) {
            throw new InvalidNicheFilters($errors);
        }
    }

    public static function defaults(): self
    {
        return new self(null, null, null, true);
    }

    /**
     * Campos do formulário: desconto_minimo, preco_minimo, preco_maximo (vazio = sem limite), exige_foto ("1").
     *
     * @param array<array-key, mixed> $form
     *
     * @throws InvalidNicheFilters
     */
    public static function fromForm(array $form): self
    {
        $errors = [];

        $discount = null;
        $raw = self::text($form['desconto_minimo'] ?? null);
        if ($raw !== '') {
            // 0 ou 0% = sem desconto mínimo.
            if (preg_match('/^(\d{1,3})\s*%?$/', $raw, $m) === 1) {
                $discount = (int) $m[1] === 0 ? null : (int) $m[1];
            } else {
                $errors['desconto_minimo'] = 'Use um número inteiro de 1 a ' . self::MAX_DISCOUNT_PCT . '.';
            }
        }

        $prices = [];
        foreach (['preco_minimo', 'preco_maximo'] as $field) {
            $raw = self::text($form[$field] ?? null);
            $prices[$field] = $raw === '' ? null : self::cents($raw);
            if ($raw !== '' && $prices[$field] === null) {
                $errors[$field] = 'Use só números, como 30 ou 1.299,90.';
            }
        }

        $requirePhoto = ($form['exige_foto'] ?? null) === '1';

        if ($errors !== []) {
            // Junta os erros de formato com os de faixa dos campos que foram lidos.
            try {
                new self(
                    isset($errors['desconto_minimo']) ? null : $discount,
                    isset($errors['preco_minimo']) ? null : $prices['preco_minimo'],
                    isset($errors['preco_maximo']) ? null : $prices['preco_maximo'],
                    $requirePhoto,
                );
            } catch (InvalidNicheFilters $e) {
                $errors += $e->errors;
            }
            throw new InvalidNicheFilters($errors);
        }

        return new self($discount, $prices['preco_minimo'], $prices['preco_maximo'], $requirePhoto);
    }

    public function isDefault(): bool
    {
        return $this->minDiscountPct === null && $this->minPriceCents === null && $this->maxPriceCents === null && $this->requirePhoto;
    }

    /** Valor em reais no formato brasileiro, sem símbolo (ex.: "1.299,90"); '' quando não há limite. */
    public static function formatCents(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return $cents % 100 === 0
            ? number_format(intdiv($cents, 100), 0, ',', '.')
            : number_format($cents / 100, 2, ',', '.');
    }

    /** "1.299,90", "1299,90", "1299.90", "30", "R$ 30" → centavos; null se o formato for inválido. */
    private static function cents(string $raw): ?int
    {
        $value = trim(preg_replace('/^R\$\s*/i', '', $raw) ?? '');
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        } elseif (preg_match('/^\d{1,9}([.,]\d{1,2})?$/', $value) !== 1) {
            return null;
        }
        [$int, $frac] = array_pad(preg_split('/[.,]/', $value) ?: [], 2, '');

        return (int) $int * 100 + (int) str_pad($frac, 2, '0');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
