<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Port\Copy\OfferCopyFailure;
use Sinergia\Application\Port\Copy\OfferCopyGenerator;
use Sinergia\Application\Port\Copy\OfferCopyRequest;
use Sinergia\Domain\Installation\InstallationId;

/**
 * Monta a legenda de UM envio:
 *  - sem IA configurada → v1 (fallback = false);
 *  - IA respondeu e o CopyValidator aprovou → v2;
 *  - QUALQUER falha (sem chave, timeout, HTTP, JSON inválido, vazio, recusa do validador, erro inesperado) → v1 com
 *    fallback = true. A IA nunca impede a publicação.
 * Título, preços, desconto e link vêm só dos parâmetros (dados do BotML), nunca da IA.
 * Logs: só provedor, modelo, desfecho, motivo/regra e duração — nunca chave, prompt ou resposta.
 */
final class OfferMessageComposer
{
    public function __construct(
        private readonly OfferCopyGenerator $generator,
        private readonly CopyValidator $validator,
        private readonly MessageBuilder $builder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function compose(InstallationId $installation, int $queueId, OfferCopyRequest $request, int $priceCents, ?int $originalCents, string $affiliateUrl): ComposedMessage
    {
        $meta = fn (bool $fallback): array => [
            'provider' => $this->generator->provider(),
            'model' => $this->generator->model(),
            'prompt_version' => $this->generator->promptVersion(),
            'fallback' => $fallback,
        ];
        $v1 = fn (bool $fallback): ComposedMessage => new ComposedMessage(
            $this->builder->caption($request->title, $priceCents, $originalCents, $affiliateUrl),
            MessageBuilder::FORMAT_VERSION,
            $meta($fallback),
        );

        $started = hrtime(true);
        try {
            $draft = $this->generator->generate($request);
            if ($draft === null) {
                return $v1(false);
            }
            $copy = $this->validator->validate($draft, $request);
        } catch (OfferCopyFailure $e) {
            $this->fallback($installation, $queueId, $e->reason, $e->rule, $e->httpStatus, $started);

            return $v1(true);
        } catch (\Throwable $e) {
            $this->fallback($installation, $queueId, 'unexpected', $e::class, null, $started);

            return $v1(true);
        }

        $this->logger->info('offer_copy.generated', [
            'installation_id' => $installation->value,
            'queue_id' => $queueId,
            'provider' => $this->generator->provider(),
            'model' => $this->generator->model(),
            'duration_ms' => self::ms($started),
        ]);

        return new ComposedMessage(
            $this->builder->captionWithCopy($request->title, $priceCents, $originalCents, $affiliateUrl, $copy),
            MessageBuilder::FORMAT_V2,
            $meta(false),
        );
    }

    private function fallback(InstallationId $installation, int $queueId, string $reason, ?string $rule, ?int $httpStatus, int|float $started): void
    {
        $this->logger->warning('offer_copy.fallback_v1', [
            'installation_id' => $installation->value,
            'queue_id' => $queueId,
            'provider' => $this->generator->provider(),
            'model' => $this->generator->model(),
            'reason' => $reason,
            'rule' => $rule,
            'http_status' => $httpStatus,
            'duration_ms' => self::ms($started),
        ]);
    }

    private static function ms(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
