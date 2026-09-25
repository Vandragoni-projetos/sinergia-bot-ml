<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

/**
 * Configuração de publicação de um destino. Janela no fuso da conta (America/Sao_Paulo), dentro do mesmo dia.
 * Regras: HH:MM válidos; início < fim; janela ≥ 30 min; intervalo inteiro de 10 a 720 min e ≤ janela; modo auto|manual.
 */
final readonly class DestinationSettings
{
    public const int MIN_INTERVAL = 10;
    public const int MAX_INTERVAL = 720;
    public const int MIN_WINDOW = 30;

    /** @param list<string> $subnicheSlugs */
    public function __construct(
        public string $nicheSlug,
        public array $subnicheSlugs,
        public string $windowStart,
        public string $windowEnd,
        public int $intervalMinutes,
        public string $mode,
    ) {
    }

    /**
     * @param array<array-key, mixed> $form campos: nicho, subnichos[], inicio, fim, intervalo, modo
     *
     * @throws InvalidDestinationSettings
     */
    public static function fromForm(array $form): self
    {
        $errors = [];
        $text = static fn (string $key): string => is_string($form[$key] ?? null) ? trim($form[$key]) : '';

        $niche = $text('nicho');
        if (preg_match('/^[a-z0-9-]{1,64}$/', $niche) !== 1) {
            $errors['nicho'] = 'Escolha um nicho.';
        }
        $subniches = [];
        foreach (is_array($form['subnichos'] ?? null) ? $form['subnichos'] : [] as $slug) {
            if (is_string($slug) && preg_match('/^[a-z0-9-]{1,64}$/', $slug) === 1) {
                $subniches[$slug] = $slug;
            }
        }
        if ($subniches === [] && !isset($errors['nicho'])) {
            $errors['subnichos'] = 'Marque pelo menos um subnicho.';
        }

        $start = self::minutes($text('inicio'));
        $end = self::minutes($text('fim'));
        if ($start === null || $end === null) {
            $errors['janela'] = 'Use horários no formato 08:00.';
        } elseif ($start >= $end) {
            $errors['janela'] = 'O horário final precisa ser depois do inicial (no mesmo dia).';
        } elseif ($end - $start < self::MIN_WINDOW) {
            $errors['janela'] = 'A janela precisa ter pelo menos 30 minutos.';
        }

        $interval = preg_match('/^\d{1,4}$/', $text('intervalo')) === 1 ? (int) $text('intervalo') : null;
        if ($interval === null || $interval < self::MIN_INTERVAL || $interval > self::MAX_INTERVAL) {
            $errors['intervalo'] = 'Use um intervalo de 10 a 720 minutos.';
        } elseif ($start !== null && $end !== null && !isset($errors['janela']) && $interval > $end - $start) {
            $errors['intervalo'] = 'O intervalo não pode ser maior que a janela de publicação.';
        }

        $mode = $text('modo');
        if (!in_array($mode, ['auto', 'manual'], true)) {
            $errors['modo'] = 'Escolha automático ou manual.';
        }

        if ($errors !== []) {
            throw new InvalidDestinationSettings($errors);
        }

        return new self($niche, array_values($subniches), $text('inicio'), $text('fim'), (int) $interval, $mode);
    }

    private static function minutes(string $time): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
