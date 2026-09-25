<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Sinergia\Application\Queue\MessageBuilder;
use Sinergia\Application\Queue\SendWindow;

final class QueueUnitsTest extends TestCase
{
    private function window(): SendWindow
    {
        return new SendWindow('09:00', '21:00', new \DateTimeZone('America/Sao_Paulo'));
    }

    public function testWindowUsesAccountTimezone(): void
    {
        $w = $this->window();
        // 12:00 UTC = 09:00 em São Paulo (UTC−3).
        self::assertTrue($w->allows(new \DateTimeImmutable('2026-09-28T12:00:00Z')));
        self::assertFalse($w->allows(new \DateTimeImmutable('2026-09-28T11:59:00Z')));
        self::assertFalse($w->allows(new \DateTimeImmutable('2026-09-29T00:00:00Z')), '21:00 local: fim é exclusivo.');
        self::assertTrue($w->allows(new \DateTimeImmutable('2026-09-28T23:59:00Z')));
    }

    public function testNextAllowed(): void
    {
        $w = $this->window();
        self::assertSame('2026-09-28T12:00:00+00:00', $w->nextAllowed(new \DateTimeImmutable('2026-09-28T08:00:00Z'))->format(DATE_ATOM));
        self::assertSame('2026-09-28T15:30:00+00:00', $w->nextAllowed(new \DateTimeImmutable('2026-09-28T15:30:00Z'))->format(DATE_ATOM));
        self::assertSame('2026-09-29T12:00:00+00:00', $w->nextAllowed(new \DateTimeImmutable('2026-09-29T01:30:00Z'))->format(DATE_ATOM), '22:30 local → amanhã 09:00.');
    }

    public function testFixedMessageWithDiscount(): void
    {
        self::assertSame(
            "Air Fryer Mondial 4L\n\nDe R$ 249,90 por R$ 179,90 (28% OFF)\n\nhttps://meli.la/2sWvfQU",
            (new MessageBuilder())->caption("  Air Fryer   Mondial\n4L ", 17990, 24990, 'https://meli.la/2sWvfQU'),
        );
    }

    public function testFixedMessageWithoutValidOriginalPriceHasNoDiscount(): void
    {
        $builder = new MessageBuilder();
        self::assertSame("Panela\n\nPor R$ 1.299,00\n\nhttps://meli.la/1rZgSMj", $builder->caption('Panela', 129900, null, 'https://meli.la/1rZgSMj'));
        self::assertSame("Panela\n\nPor R$ 99,90\n\nhttps://meli.la/1rZgSMj", $builder->caption('Panela', 9990, 9990, 'https://meli.la/1rZgSMj'), 'Original igual ao atual: sem desconto.');
        self::assertStringNotContainsString('OFF', $builder->caption('Panela', 9990, 5000, 'https://meli.la/x'));
    }

    public function testLinkIsNeverAltered(): void
    {
        $url = 'https://meli.la/AbCdEfG';
        $caption = (new MessageBuilder())->caption('X', 1000, 2000, $url);
        self::assertStringEndsWith("\n" . $url, $caption);
        self::assertSame(1, substr_count($caption, 'meli.la'));
    }
}
