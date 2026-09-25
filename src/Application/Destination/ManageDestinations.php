<?php

declare(strict_types=1);

namespace Sinergia\Application\Destination;

use Psr\Log\LoggerInterface;
use Sinergia\Application\Auth\TenantContext;
use Sinergia\Application\Port\Destination\DestinationStore;
use Sinergia\Application\Port\WhatsApp\GroupSummary;
use Sinergia\Application\Port\WhatsApp\WhatsAppConnectionStore;
use Sinergia\Application\Port\WhatsApp\WhatsAppProvider;
use Sinergia\Application\Port\WhatsApp\WhatsAppProviderFailure;
use Sinergia\Shared\Clock\Clock;
use Sinergia\Shared\Config\SensitiveValue;

/**
 * Destinos da conta AUTENTICADA (a conta vem só do TenantContext).
 * - Grupos/canais só vêm da PRÓPRIA conexão WhatsApp (token da conta); o navegador só escolhe entre eles por chave aleatória.
 * - Nunca aceita provider_ref, installation_id ou token enviados pelo navegador.
 * - Fatos técnicos e declarações ficam separados; o rótulo é sempre derivado por DestinationEligibility.
 * - Envio de teste: desligado por padrão (só com WHATSAPP_TEST_SEND_ENABLED e URL de imagem configurados).
 */
final class ManageDestinations
{
    public const int GROUP_PAGE_SIZE = 500;
    public const int MAX_GROUP_PAGES = 10;
    public const string TEST_CAPTION = "Teste do SINERGIA BOT ML: este destino está pronto para receber ofertas.";

    /** @param \Closure(): WhatsAppProvider $provider */
    public function __construct(
        private readonly DestinationStore $store,
        private readonly WhatsAppConnectionStore $connections,
        private readonly \Closure $provider,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly ?string $testImageUrl = null,
    ) {
    }

    public function testSendEnabled(): bool
    {
        return $this->testImageUrl !== null;
    }

    /**
     * Relê grupos e canais do WhatsApp da conta e atualiza os fatos técnicos dos destinos cadastrados.
     *
     * @throws DestinationRejected
     * @throws WhatsAppProviderFailure
     */
    public function sync(TenantContext $tenant): int
    {
        $token = $this->token($tenant);
        $provider = ($this->provider)();
        $now = $this->clock->now();

        $groups = [];
        $complete = false;
        for ($page = 0; $page < self::MAX_GROUP_PAGES; $page++) {
            $result = $provider->listGroups($token, self::GROUP_PAGE_SIZE, $page * self::GROUP_PAGE_SIZE);
            foreach ($result->groups as $group) {
                $groups[$group->jid] = $group;
            }
            if (count($result->groups) < self::GROUP_PAGE_SIZE || ($result->total !== null && count($groups) >= $result->total)) {
                $complete = true;
                break;
            }
        }
        $channels = [];
        foreach ($provider->listChannels($token) as $channel) {
            $channels[$channel->jid] = $channel;
        }

        $available = [];
        foreach ($channels as $channel) {
            $available[] = new AvailableDestination(self::key(), 'channel', $channel->jid, $channel->name, $channel->weAreAdmin, null, null, null, null);
        }
        foreach ($groups as $group) {
            $available[] = new AvailableDestination(self::key(), 'group', $group->jid, $group->name, $group->ownerIsAdmin, $group->joinApprovalRequired, $group->announceOnly, $group->isCommunity, $group->participants);
        }

        // Atualiza os destinos cadastrados; grupos presentes são reconsultados em /group/info (link de convite).
        foreach ($this->store->all($tenant->installationId) as $destination) {
            if ($destination->type === 'channel') {
                $channel = $channels[$destination->providerRef] ?? null;
                $facts = $channel === null
                    ? self::withPresence($destination->facts, false)
                    : new DestinationFacts(true, true, $channel->weAreAdmin, null, null, null, null);
                $name = $channel === null ? $destination->name : $channel->name;
            } elseif (isset($groups[$destination->providerRef])) {
                $facts = $this->groupFacts($provider, $token, $destination->providerRef, $groups[$destination->providerRef]);
                $name = $groups[$destination->providerRef]->name;
            } else {
                // Só marca "removido" se a listagem de grupos veio completa.
                $facts = $complete ? self::withPresence($destination->facts, false) : $destination->facts;
                $name = $destination->name;
            }
            $this->store->updateFacts($tenant->installationId, $destination->id, $name !== '' ? $name : $destination->name, $facts, $now);
            $this->reevaluate($tenant, $destination->id);
        }

        $this->store->replaceAvailable($tenant->installationId, $available, $now);
        $this->logger->info('destinations.synced', [
            'installation_id' => $tenant->installationId->value, 'groups' => count($groups), 'channels' => count($channels), 'complete' => $complete,
        ]);

        return count($available);
    }

    /**
     * Cadastra um grupo/canal ESCOLHIDO entre os encontrados no WhatsApp da conta.
     *
     * @throws DestinationRejected
     * @throws WhatsAppProviderFailure
     */
    public function add(TenantContext $tenant, string $pickKey): DestinationRecord
    {
        $candidate = preg_match('/^[a-f0-9]{20}$/', $pickKey) === 1 ? $this->store->availableByPickKey($tenant->installationId, $pickKey) : null;
        if ($candidate === null) {
            throw new DestinationRejected(DestinationRejected::NOT_FOUND);
        }
        $token = $this->token($tenant);
        $provider = ($this->provider)();

        // Reconsulta com o token DA CONTA antes de gravar: confirma que o destino é da própria conexão.
        if ($candidate->type === 'group') {
            try {
                $group = $provider->groupInfo($token, $candidate->providerRef);
            } catch (WhatsAppProviderFailure $e) {
                if (in_array($e->errorCode, [WhatsAppProviderFailure::NOT_FOUND, WhatsAppProviderFailure::FORBIDDEN], true)) {
                    throw new DestinationRejected(DestinationRejected::NOT_FOUND);
                }
                throw $e;
            }
            $facts = self::factsFromGroup($group, $group->hasInviteLink);
            $name = $group->name !== '' ? $group->name : $candidate->name;
        } else {
            $channel = null;
            foreach ($provider->listChannels($token) as $item) {
                if ($item->jid === $candidate->providerRef) {
                    $channel = $item;
                }
            }
            if ($channel === null) {
                throw new DestinationRejected(DestinationRejected::NOT_FOUND);
            }
            $facts = new DestinationFacts(true, true, $channel->weAreAdmin, null, null, null, null);
            $name = $channel->name !== '' ? $channel->name : $candidate->name;
        }

        $record = $this->store->add($tenant->installationId, $candidate->type, $candidate->providerRef, $name !== '' ? $name : 'Sem nome', $facts, $tenant->userId, $this->clock->now());
        $this->reevaluate($tenant, $record->id);
        $this->logger->info('destinations.added', ['installation_id' => $tenant->installationId->value, 'type' => $candidate->type]);

        return $this->store->byPublicKey($tenant->installationId, $record->publicKey) ?? $record;
    }

    /**
     * @param array<array-key, mixed> $form
     *
     * @throws DestinationRejected
     * @throws InvalidDestinationSettings
     */
    public function configure(TenantContext $tenant, string $publicKey, array $form): void
    {
        $destination = $this->find($tenant, $publicKey);
        $settings = DestinationSettings::fromForm($form);

        $niche = null;
        foreach ($this->store->nicheOptions($tenant->installationId) as $option) {
            if ($option['slug'] === $settings->nicheSlug) {
                $niche = $option;
            }
        }
        if ($niche === null) {
            throw new InvalidDestinationSettings(['nicho' => 'Escolha um nicho com subnichos ativos na tela Nichos.']);
        }
        $bySlug = array_column($niche['subniches'], 'id', 'slug');
        $ids = [];
        foreach ($settings->subnicheSlugs as $slug) {
            if (!isset($bySlug[$slug])) {
                throw new InvalidDestinationSettings(['subnichos' => 'Marque só subnichos ativos deste nicho.']);
            }
            $ids[] = (int) $bySlug[$slug];
        }

        $this->store->updateSettings($tenant->installationId, $destination->id, $niche['id'], $ids, $settings->mode, $settings->windowStart, $settings->windowEnd, $settings->intervalMinutes, $this->clock->now());
        $this->reevaluate($tenant, $destination->id);
    }

    /** @throws DestinationRejected */
    public function setPaused(TenantContext $tenant, string $publicKey, bool $paused): void
    {
        $destination = $this->find($tenant, $publicKey);
        if (!$paused) {
            if (!$destination->isConfigured()) {
                throw new DestinationRejected(DestinationRejected::NOT_READY);
            }
            if (!$destination->eligibility()->isEligible()) {
                throw new DestinationRejected(DestinationRejected::NOT_ELIGIBLE);
            }
        }
        $this->store->setPaused($tenant->installationId, $destination->id, $paused, $this->clock->now());
        $this->reevaluate($tenant, $destination->id);
    }

    /** @throws DestinationRejected */
    public function declare(TenantContext $tenant, string $publicKey, string $kind, bool $declared): void
    {
        $destination = $this->find($tenant, $publicKey);
        if (!array_key_exists($kind, DestinationDeclarations::TEXTS)) {
            throw new DestinationRejected(DestinationRejected::NOT_FOUND);
        }
        if ($kind === DestinationDeclarations::PUBLIC_GROUP && $destination->type !== 'group') {
            throw new DestinationRejected(DestinationRejected::NOT_A_GROUP);
        }
        $this->store->setDeclaration($tenant->installationId, $destination->id, $kind, $declared, $tenant->userId, DestinationDeclarations::VERSION, $this->clock->now());
        $this->reevaluate($tenant, $destination->id);
        $this->logger->info('destinations.declaration', [
            'installation_id' => $tenant->installationId->value, 'user_id' => $tenant->userId, 'kind' => $kind, 'declared' => $declared, 'version' => DestinationDeclarations::VERSION,
        ]);
    }

    /** @throws DestinationRejected */
    public function remove(TenantContext $tenant, string $publicKey): void
    {
        $this->store->remove($tenant->installationId, $this->find($tenant, $publicKey)->id);
    }

    /**
     * Envio de teste (mensagem fixa com imagem) para um destino ELEGÍVEL da conta. Desligado por padrão.
     *
     * @throws DestinationRejected
     * @throws WhatsAppProviderFailure
     */
    public function sendTest(TenantContext $tenant, string $publicKey): void
    {
        if ($this->testImageUrl === null) {
            throw new DestinationRejected(DestinationRejected::TEST_SEND_DISABLED);
        }
        $destination = $this->find($tenant, $publicKey);
        if (!$destination->eligibility()->isEligible()) {
            throw new DestinationRejected(DestinationRejected::NOT_ELIGIBLE);
        }
        ($this->provider)()->sendImage($this->token($tenant), $destination->providerRef, $this->testImageUrl, self::TEST_CAPTION);
        $this->store->markTestSent($tenant->installationId, $destination->id, $this->clock->now());
        $this->logger->info('destinations.test_sent', ['installation_id' => $tenant->installationId->value, 'type' => $destination->type]);
    }

    /** @throws DestinationRejected */
    private function find(TenantContext $tenant, string $publicKey): DestinationRecord
    {
        $destination = preg_match('/^[a-f0-9]{20}$/', $publicKey) === 1 ? $this->store->byPublicKey($tenant->installationId, $publicKey) : null;

        return $destination ?? throw new DestinationRejected(DestinationRejected::NOT_FOUND);
    }

    private function reevaluate(TenantContext $tenant, int $id): void
    {
        foreach ($this->store->all($tenant->installationId) as $destination) {
            if ($destination->id !== $id) {
                continue;
            }
            $eligibility = $destination->eligibility();
            $status = match (true) {
                !$eligibility->isEligible() => 'ineligible',
                $destination->userPaused || !$destination->isConfigured() => 'paused',
                default => 'active',
            };
            $this->store->saveEvaluation($tenant->installationId, $id, $eligibility, $status, $this->clock->now());
        }
    }

    /** @throws DestinationRejected */
    private function token(TenantContext $tenant): SensitiveValue
    {
        $connection = $this->connections->find($tenant->installationId);
        if ($connection === null || $connection->token === null || $connection->status !== 'connected') {
            throw new DestinationRejected(DestinationRejected::WHATSAPP_NOT_CONNECTED);
        }

        return $connection->token;
    }

    /** @throws WhatsAppProviderFailure */
    private function groupFacts(WhatsAppProvider $provider, SensitiveValue $token, string $jid, GroupSummary $listed): DestinationFacts
    {
        try {
            $info = $provider->groupInfo($token, $jid);

            return self::factsFromGroup($info, $info->hasInviteLink);
        } catch (WhatsAppProviderFailure $e) {
            if (!in_array($e->errorCode, [WhatsAppProviderFailure::NOT_FOUND, WhatsAppProviderFailure::FORBIDDEN], true)) {
                throw $e;
            }

            // Sem detalhes: usa só o que a listagem informou; link de convite fica desconhecido.
            return self::factsFromGroup($listed, null);
        }
    }

    private static function factsFromGroup(GroupSummary $group, ?bool $hasInviteLink): DestinationFacts
    {
        return new DestinationFacts(false, true, $group->ownerIsAdmin, $hasInviteLink, $group->joinApprovalRequired, $group->announceOnly, $group->isCommunity);
    }

    private static function withPresence(DestinationFacts $facts, bool $present): DestinationFacts
    {
        return new DestinationFacts($facts->isChannel, $present, $facts->weAreAdmin, $facts->hasInviteLink, $facts->joinApproval, $facts->announceOnly, $facts->isCommunity);
    }

    private static function key(): string
    {
        return bin2hex(random_bytes(10));
    }
}
