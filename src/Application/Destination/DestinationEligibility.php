<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

/**
 * Regra de elegibilidade (plano F1 v2.1, seção 2.2). Ordem de verificação = ordem abaixo; vale o PRIMEIRO motivo.
 *
 * Técnicos (independem de qualquer declaração do cliente):
 *   1. não aparece mais no WhatsApp da conta         → not_found_in_whatsapp
 *   Canal:
 *   2. papel confirmado de seguidor (não admin)       → channel_not_admin
 *   3. papel não informado                            → insufficient_information
 *   Grupo:
 *   2. comunidade ou subgrupo de comunidade           → community_group
 *   3. aprovação obrigatória para entrar              → join_approval_required
 *   4. nosso número confirmado como não-admin         → not_admin
 *   5. link de convite confirmado como inexistente    → no_invite_link
 *   6. admin, link ou aprovação não informados        → insufficient_information
 * Declarações (registro, não prova):
 *   7. grupo sem declaração de público                → public_declaration_missing
 *   8. destino sem declaração de Mídia cadastrada     → media_declaration_missing
 * Resultado: canal → "Canal público"; grupo → "Grupo declarado público". Nunca "público comprovado".
 */
final class DestinationEligibility
{
    public const string NOT_FOUND = 'not_found_in_whatsapp';
    public const string CHANNEL_NOT_ADMIN = 'channel_not_admin';
    public const string COMMUNITY = 'community_group';
    public const string JOIN_APPROVAL = 'join_approval_required';
    public const string NOT_ADMIN = 'not_admin';
    public const string NO_INVITE_LINK = 'no_invite_link';
    public const string INSUFFICIENT = 'insufficient_information';
    public const string PUBLIC_DECLARATION_MISSING = 'public_declaration_missing';
    public const string MEDIA_DECLARATION_MISSING = 'media_declaration_missing';

    public static function evaluate(DestinationFacts $facts, bool $publicDeclared, bool $mediaDeclared): Eligibility
    {
        $technical = self::technicalBlock($facts);
        if ($technical !== null) {
            return new Eligibility(Eligibility::INELIGIBLE, $technical, $technical);
        }
        if (!$facts->isChannel && !$publicDeclared) {
            return new Eligibility(Eligibility::INELIGIBLE, self::PUBLIC_DECLARATION_MISSING, null);
        }
        if (!$mediaDeclared) {
            return new Eligibility(Eligibility::INELIGIBLE, self::MEDIA_DECLARATION_MISSING, null);
        }

        return new Eligibility($facts->isChannel ? Eligibility::CHANNEL_PUBLIC : Eligibility::GROUP_DECLARED_PUBLIC, null, null);
    }

    public static function technicalBlock(DestinationFacts $facts): ?string
    {
        if (!$facts->presentInWhatsApp) {
            return self::NOT_FOUND;
        }
        if ($facts->isChannel) {
            return match ($facts->weAreAdmin) {
                true => null,
                false => self::CHANNEL_NOT_ADMIN,
                null => self::INSUFFICIENT,
            };
        }

        return match (true) {
            $facts->isCommunity === true => self::COMMUNITY,
            $facts->joinApproval === true => self::JOIN_APPROVAL,
            $facts->weAreAdmin === false => self::NOT_ADMIN,
            $facts->hasInviteLink === false => self::NO_INVITE_LINK,
            $facts->weAreAdmin === null || $facts->hasInviteLink === null || $facts->joinApproval === null => self::INSUFFICIENT,
            default => null,
        };
    }
}
