<?php

declare(strict_types=1);

namespace Sinergia\Application\Queue;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Destination\ManageDestinations;
use Sinergia\Application\Offer\OfferChooser;
use Sinergia\Application\Port\MercadoLivre\MercadoLivreFailure;
use Sinergia\Application\Port\Offer\CatalogSourceFactory;
use Sinergia\Application\Port\Queue\QueueStore;
use Sinergia\Application\Port\WhatsApp\WhatsAppConnectionStore;
use Sinergia\Application\Port\WhatsApp\WhatsAppProvider;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure as Failure;
use Sinergia\Domain\Installation\InstallationId;
use Sinergia\Shared\Clock\Clock;

/**
 * Envio (plano F1 v2.1, seção 5, passo 3), SEMPRE pelo WhatsAppProvider e sempre com dados DA CONTA do item.
 *
 * 1. Reserva atômica (scheduled → sending + claim_token): só um worker fica com o item.
 * 2. Revalida na hora: bot da conta ativo · destino existe, ativo (elegível e não pausado) · modo manual exige aprovação ·
 *    janela · cadência · link ATIVO da conta para o produto (usa o atual, se o anterior foi substituído) ·
 *    WhatsApp conectado · produto e preço ATUAIS no ML (token da conta) · filtros do nicho · foto.
 * 3. Mensagem fixa com o preço atual; link exatamente como gravado.
 * 4. Marca "envio iniciado" ANTES de chamar o provedor. Retry só quando o provedor comprovadamente NÃO processou
 *    (429, 503, 401/403); timeout, rede, resposta inválida e demais 5xx = resultado incerto → 'failed', sem reenvio.
 */
final class QueueSender
{
    public const int MAX_ATTEMPTS = 3;
    /** Espera (minutos) antes da 2ª e da 3ª tentativa, após falha em que o envio comprovadamente não ocorreu. */
    public const array BACKOFF_MINUTES = [1 => 5, 2 => 15, 3 => 45];
    public const int AUTH_RETRY_MINUTES = 30;
    public const int SOURCE_RETRY_MINUTES = 15;

    /** @param \Closure(): WhatsAppProvider $provider */
    public function __construct(
        private readonly QueueStore $store,
        private readonly WhatsAppConnectionStore $connections,
        private readonly \Closure $provider,
        private readonly CatalogSourceFactory $catalog,
        private readonly OfferChooser $chooser,
        private readonly MessageBuilder $messages,
        private readonly ManageDestinations $destinations,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, int> resultado por desfecho */
    public function sendDue(InstallationId $installation, string $workerId, int $limit): array
    {
        $summary = [];
        foreach ($this->store->dueItemIds($installation, $this->clock->now(), $limit) as $id) {
            $token = bin2hex(random_bytes(16));
            if (!$this->store->claim($installation, $id, $token, $this->clock->now())) {
                continue;   // outro worker reservou antes
            }
            $outcome = $this->process($installation, $id, $token, $workerId);
            $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
        }

        return $summary;
    }

    private function process(InstallationId $installation, int $id, string $token, string $workerId): string
    {
        $item = $this->store->loadClaimed($installation, $id, $token);
        if ($item === null) {
            return 'lost';
        }
        $now = $this->clock->now();
        $release = fn (string $error, ?\DateTimeImmutable $scheduledFor = null, ?\DateTimeImmutable $next = null): string => $this->released($installation, $item, $error, $scheduledFor ?? $now, $next);

        // 1) Conta e destino.
        if (!$item->botActive) {
            return $release('bot_paused');
        }
        if ($item->destinationId === null || $item->destinationRef === null) {
            $this->store->finish($installation, $id, $token, 'cancelled', 'destination_removed', $now);

            return 'cancelled';
        }
        if ($item->destinationStatus !== 'active') {
            return $release('destination_' . $item->destinationStatus);
        }
        if ($item->destinationMode === 'manual' && $item->decidedBy === null) {
            $this->store->finish($installation, $id, $token, 'pending_approval', null, $now);

            return 'pending_approval';
        }

        // 2) Janela e cadência.
        $window = new SendWindow((string) $item->windowStart, (string) $item->windowEnd, new \DateTimeZone($item->timezone));
        if (!$window->allows($now)) {
            return $release('outside_window', $window->nextAllowed($now));
        }
        if ($item->destinationLastSentAt !== null) {
            $earliest = $item->destinationLastSentAt->modify('+' . (int) $item->intervalMinutes . ' minutes');
            if ($earliest > $now) {
                return $release('cadence', $window->nextAllowed($earliest));
            }
        }

        // 3) Link ATIVO da própria conta para este produto (o substituto, se houve troca).
        if ($item->currentLinkId === null || $item->currentLinkUrl === null) {
            $this->store->finish($installation, $id, $token, 'awaiting_affiliate_link', 'link_missing', $now);

            return 'awaiting_affiliate_link';
        }

        // 4) Conexão WhatsApp da conta.
        $connection = $this->connections->find($installation);
        if ($connection === null || $connection->token === null || $connection->status !== 'connected') {
            return $release('whatsapp_not_connected', $now, $now->modify('+' . self::SOURCE_RETRY_MINUTES . ' minutes'));
        }

        // 5) Produto e preço ATUAIS (nunca reaproveita o preço planejado).
        try {
            $source = $this->catalog->forInstallation($installation);
            $product = $source->product($item->productId);
            $offer = $this->chooser->chooseFromBuyBox($product) ?? $this->chooser->chooseFromItems($source->offers($item->productId)['offers']);
        } catch (MercadoLivreFailure $e) {
            if (in_array($e->failureHttpStatus(), [403, 404], true)) {
                $this->store->finish($installation, $id, $token, 'skipped', 'product_unavailable', $now);

                return 'skipped';
            }
            $minutes = $e->failureHttpStatus() === 401 ? self::AUTH_RETRY_MINUTES : self::SOURCE_RETRY_MINUTES;

            return $release('ml_' . $e->failureCode(), $now, $now->modify('+' . $minutes . ' minutes'));
        }
        $skip = match (true) {
            $offer === null => 'no_offer',
            $product->catalogStatus !== null && $product->catalogStatus !== 'active' => 'product_inactive',
            $product->permalink === null => 'no_permalink',
            $product->pictureUrl === null => 'no_photo',
            $item->filters->minPriceCents !== null && $offer->priceCents < $item->filters->minPriceCents,
            $item->filters->maxPriceCents !== null && $offer->priceCents > $item->filters->maxPriceCents,
            $item->filters->minDiscountPct !== null && $offer->discountPct < $item->filters->minDiscountPct => 'filters_no_longer_match',
            default => null,
        };
        if ($skip !== null || $offer === null) {
            $this->store->finish($installation, $id, $token, 'skipped', $skip, $now);

            return 'skipped';
        }

        // 6) Mensagem fixa com o preço atual e o link exatamente como gravado.
        if ($item->attempts >= self::MAX_ATTEMPTS) {
            $this->store->finish($installation, $id, $token, 'failed', 'max_attempts', $now);

            return 'failed';
        }
        $caption = $this->messages->caption($product->name, $offer->priceCents, $offer->originalPriceCents, $item->currentLinkUrl);
        $message = [
            'format' => MessageBuilder::FORMAT_VERSION,
            'caption' => $caption,
            'image_url' => $product->pictureUrl,
            'affiliate_url' => $item->currentLinkUrl,
            'price' => $offer->priceCents,
            'original_price' => $offer->originalPriceCents,
            'discount_pct' => $offer->discountPct,
            'offer_rule' => $offer->rule,
        ];
        if (!$this->store->markSendStarted($installation, $id, $token, $item->currentLinkId, $message, $offer->priceCents, $offer->originalPriceCents, $offer->discountPct, $now)) {
            return 'lost';
        }
        $attemptNo = $item->attempts + 1;
        $attempt = $this->store->startAttempt($installation, $id, $attemptNo, $workerId, $now);

        // 7) Envio exclusivamente pelo WhatsAppProvider.
        try {
            $sent = ($this->provider)()->sendImage($connection->token, $item->destinationRef, (string) $product->pictureUrl, $caption);
        } catch (Failure $e) {
            return $this->failed($installation, $item, $attempt, $attemptNo, $e);
        } catch (\Throwable $e) {
            // Erro inesperado DEPOIS de iniciar o envio: resultado incerto, nunca reenvia.
            $this->store->finish($installation, $id, $token, 'failed', 'unknown_outcome_exception', $this->clock->now());
            $this->store->finishAttempt($installation, $attempt, 'unknown', 'exception', null, $this->clock->now());
            $this->logger->error('queue.send_exception', ['installation_id' => $installation->value, 'queue_id' => $id, 'exception' => $e::class]);

            return 'failed';
        }
        $this->store->markSent($installation, $id, $token, $sent->providerMessageId, $this->clock->now());
        $this->store->finishAttempt($installation, $attempt, 'sent', null, null, $this->clock->now());
        $this->logger->info('queue.sent', ['installation_id' => $installation->value, 'queue_id' => $id, 'attempt' => $attemptNo]);

        return 'sent';
    }

    private function failed(InstallationId $installation, ClaimedItem $item, int $attempt, int $attemptNo, Failure $e): string
    {
        $now = $this->clock->now();
        $code = $e->errorCode;
        $notProcessed = $code === Failure::RATE_LIMITED
            || ($code === Failure::SERVER_ERROR && $e->httpStatus === 503)
            || in_array($code, [Failure::UNAUTHORIZED, Failure::FORBIDDEN], true);

        if ($code === Failure::NOT_FOUND) {
            // Destino sumiu do WhatsApp: falha definitiva e o destino fica inelegível já.
            $this->store->finish($installation, $item->id, $item->claimToken, 'failed', 'destination_not_found', $now);
            $this->store->finishAttempt($installation, $attempt, 'failed', $code, $e->httpStatus, $now);
            if ($item->destinationId !== null) {
                $this->destinations->markMissing($installation, $item->destinationId);
            }
            $outcome = 'failed';
        } elseif ($notProcessed && $attemptNo < self::MAX_ATTEMPTS) {
            $minutes = in_array($code, [Failure::UNAUTHORIZED, Failure::FORBIDDEN], true)
                ? self::AUTH_RETRY_MINUTES
                : max(self::BACKOFF_MINUTES[$attemptNo] ?? 45, (int) ceil(($e->retryAfterSeconds ?? 0) / 60));
            $this->store->release($installation, $item->id, $item->claimToken, $now, $now->modify('+' . $minutes . ' minutes'), $code, $now);
            $this->store->finishAttempt($installation, $attempt, 'retry', $code, $e->httpStatus, $now);
            $outcome = 'retry';
        } elseif ($notProcessed) {
            $this->store->finish($installation, $item->id, $item->claimToken, 'failed', $code . '_max_attempts', $now);
            $this->store->finishAttempt($installation, $attempt, 'failed', $code, $e->httpStatus, $now);
            $outcome = 'failed';
        } else {
            // Timeout, rede, resposta inválida, 5xx (≠ 503), outros: a mensagem PODE ter saído → nunca reenvia.
            $this->store->finish($installation, $item->id, $item->claimToken, 'failed', 'unknown_outcome_' . $code, $now);
            $this->store->finishAttempt($installation, $attempt, 'unknown', $code, $e->httpStatus, $now);
            $outcome = 'failed';
        }
        $this->logger->warning('queue.send_failed', [
            'installation_id' => $installation->value, 'queue_id' => $item->id, 'attempt' => $attemptNo, 'error' => $code, 'http_status' => $e->httpStatus, 'outcome' => $outcome,
        ]);

        return $outcome;
    }

    private function released(InstallationId $installation, ClaimedItem $item, string $error, \DateTimeImmutable $scheduledFor, ?\DateTimeImmutable $next): string
    {
        $this->store->release($installation, $item->id, $item->claimToken, $scheduledFor, $next, $error, $this->clock->now());

        return 'released';
    }
}
