<?php

declare(strict_types=1);

namespace Sinergia\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinergia\Application\Destination\DestinationEligibility as Rule;
use Sinergia\Application\Destination\DestinationFacts;
use Sinergia\Application\Destination\Eligibility;

final class DestinationEligibilityTest extends TestCase
{
    /** @return iterable<string, array{DestinationFacts, bool, bool, string, ?string, ?string}> */
    public static function cases(): iterable
    {
        $openGroup = new DestinationFacts(false, true, true, true, false, true, false);
        $channel = new DestinationFacts(true, true, true, null, null, null, null);

        // Canais
        yield 'canal admin + Mídia' => [$channel, false, true, Eligibility::CHANNEL_PUBLIC, null, null];
        yield 'canal admin sem Mídia' => [$channel, false, false, Eligibility::INELIGIBLE, Rule::MEDIA_DECLARATION_MISSING, null];
        yield 'canal só seguido' => [new DestinationFacts(true, true, false, null, null, null, null), false, true, Eligibility::INELIGIBLE, Rule::CHANNEL_NOT_ADMIN, Rule::CHANNEL_NOT_ADMIN];
        yield 'canal papel desconhecido' => [new DestinationFacts(true, true, null, null, null, null, null), false, true, Eligibility::INELIGIBLE, Rule::INSUFFICIENT, Rule::INSUFFICIENT];
        yield 'canal removido' => [new DestinationFacts(true, false, true, null, null, null, null), false, true, Eligibility::INELIGIBLE, Rule::NOT_FOUND, Rule::NOT_FOUND];

        // Grupos
        yield 'grupo aberto + 2 declarações' => [$openGroup, true, true, Eligibility::GROUP_DECLARED_PUBLIC, null, null];
        yield 'grupo aberto sem declaração pública' => [$openGroup, false, true, Eligibility::INELIGIBLE, Rule::PUBLIC_DECLARATION_MISSING, null];
        yield 'grupo aberto sem Mídia' => [$openGroup, true, false, Eligibility::INELIGIBLE, Rule::MEDIA_DECLARATION_MISSING, null];
        yield 'aprovação obrigatória mesmo declarado' => [new DestinationFacts(false, true, true, true, true, true, false), true, true, Eligibility::INELIGIBLE, Rule::JOIN_APPROVAL, Rule::JOIN_APPROVAL];
        yield 'comunidade' => [new DestinationFacts(false, true, true, true, false, true, true), true, true, Eligibility::INELIGIBLE, Rule::COMMUNITY, Rule::COMMUNITY];
        yield 'não admin' => [new DestinationFacts(false, true, false, null, false, false, false), true, true, Eligibility::INELIGIBLE, Rule::NOT_ADMIN, Rule::NOT_ADMIN];
        yield 'sem link de convite' => [new DestinationFacts(false, true, true, false, false, true, false), true, true, Eligibility::INELIGIBLE, Rule::NO_INVITE_LINK, Rule::NO_INVITE_LINK];
        yield 'admin desconhecido' => [new DestinationFacts(false, true, null, true, false, true, false), true, true, Eligibility::INELIGIBLE, Rule::INSUFFICIENT, Rule::INSUFFICIENT];
        yield 'link desconhecido' => [new DestinationFacts(false, true, true, null, false, true, false), true, true, Eligibility::INELIGIBLE, Rule::INSUFFICIENT, Rule::INSUFFICIENT];
        yield 'aprovação desconhecida' => [new DestinationFacts(false, true, true, true, null, true, false), true, true, Eligibility::INELIGIBLE, Rule::INSUFFICIENT, Rule::INSUFFICIENT];
        yield 'nada informado' => [new DestinationFacts(false, true, null, null, null, null, null), true, true, Eligibility::INELIGIBLE, Rule::INSUFFICIENT, Rule::INSUFFICIENT];
        yield 'grupo removido' => [new DestinationFacts(false, false, true, true, false, true, false), true, true, Eligibility::INELIGIBLE, Rule::NOT_FOUND, Rule::NOT_FOUND];
        // "Só admins enviam" é desejável, não decisivo; comunidade desconhecida não bloqueia sozinha.
        yield 'grupo aberto em que todos enviam' => [new DestinationFacts(false, true, true, true, false, false, null), true, true, Eligibility::GROUP_DECLARED_PUBLIC, null, null];
    }

    #[DataProvider('cases')]
    public function testRule(DestinationFacts $facts, bool $public, bool $media, string $label, ?string $reason, ?string $technical): void
    {
        $result = Rule::evaluate($facts, $public, $media);

        self::assertSame($label, $result->label);
        self::assertSame($reason, $result->reason);
        self::assertSame($technical, $result->techBlockingReason);
        self::assertSame($label !== Eligibility::INELIGIBLE, $result->isEligible());
    }

    public function testChannelNeverUsesGroupLabelAndDeclarationNeverOverridesTechnicalBlock(): void
    {
        $blocked = new DestinationFacts(false, true, true, true, true, true, false);
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$public, $media]) {
            self::assertSame(Rule::JOIN_APPROVAL, Rule::evaluate($blocked, $public, $media)->reason);
        }
        self::assertSame(Eligibility::CHANNEL_PUBLIC, Rule::evaluate(new DestinationFacts(true, true, true, null, null, null, null), true, true)->label);
    }
}
