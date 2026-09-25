<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

use Sinergia\Shared\Config\SensitiveValue;

/**
 * Provedor de WhatsApp (hoje Uazapi; trocável sem mudar o resto da aplicação).
 * Cada operação de instância recebe o token DAQUELA instância; a aplicação decide qual conta pode usá-lo.
 * Toda falha é WhatsAppProviderFailure, com mensagem genérica (nunca o texto bruto do provedor nem segredos).
 *
 * Etapa 5 usa: createInstance, connect, status, disconnect. listGroups e sendMedia ficam para a etapa 6.
 */
interface WhatsAppProvider
{
    /** @throws WhatsAppProviderFailure */
    public function createInstance(string $name): ProviderInstance;

    /**
     * Inicia a conexão: sem telefone → QR code; com telefone (só dígitos, 10–15) → código de pareamento.
     *
     * @throws WhatsAppProviderFailure
     */
    public function connect(SensitiveValue $instanceToken, ?string $phone = null): ConnectionSnapshot;

    /** @throws WhatsAppProviderFailure */
    public function status(SensitiveValue $instanceToken): ConnectionSnapshot;

    /** @throws WhatsAppProviderFailure */
    public function disconnect(SensitiveValue $instanceToken): ConnectionSnapshot;

    /** @throws WhatsAppProviderFailure */
    public function listGroups(SensitiveValue $instanceToken, int $limit, int $offset): GroupPage;

    /** @throws WhatsAppProviderFailure */
    public function sendImage(SensitiveValue $instanceToken, string $chatId, string $imageUrl, string $caption): SentMessage;
}
