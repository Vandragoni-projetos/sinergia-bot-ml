<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

/**
 * Janela diária de publicação de um destino, no fuso da conta: [início, fim) no mesmo dia.
 * Toda conta de horário é feita no fuso local e devolvida em UTC.
 */
final readonly class SendWindow
{
    public function __construct(
        private string $start,
        private string $end,
        private \DateTimeZone $timezone,
    ) {
    }

    public function allows(\DateTimeImmutable $moment): bool
    {
        $local = $moment->setTimezone($this->timezone)->format('H:i');

        return $local >= $this->start && $local < $this->end;
    }

    /** O primeiro instante >= $from dentro da janela (hoje, ou no início da janela do próximo dia). */
    public function nextAllowed(\DateTimeImmutable $from): \DateTimeImmutable
    {
        $local = $from->setTimezone($this->timezone);
        $hm = $local->format('H:i');
        if ($hm < $this->start) {
            $local = $this->at($local, $this->start);
        } elseif ($hm >= $this->end) {
            $local = $this->at($local->modify('+1 day'), $this->start);
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    private function at(\DateTimeImmutable $day, string $hm): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $day->setTime($h, $m, 0, 0);
    }
}
