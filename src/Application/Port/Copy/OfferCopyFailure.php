<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\Copy;

/**
 * Falha ao obter a copy. A mensagem é sempre genérica: nunca contém chave, prompt, resposta do provedor ou dado do
 * produto. Em qualquer caso o BotML publica com a mensagem fixa v1.
 */
final class OfferCopyFailure extends \RuntimeException
{
    public const string NOT_CONFIGURED = 'not_configured';
    public const string TIMEOUT = 'timeout';
    public const string NETWORK_ERROR = 'network_error';
    public const string HTTP_ERROR = 'http_error';
    public const string INVALID_JSON = 'invalid_json';
    public const string EMPTY_RESPONSE = 'empty_response';
    public const string REFUSED = 'refused';
    public const string TRUNCATED = 'truncated';
    public const string REJECTED = 'rejected';

    /** @param ?string $rule regra do validador que recusou (só com REJECTED) */
    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        public readonly ?string $rule = null,
    ) {
        parent::__construct(sprintf('Copy da oferta indisponível: %s%s%s.', $reason, $rule === null ? '' : ' (' . $rule . ')', $httpStatus === null ? '' : ' (HTTP ' . $httpStatus . ')'));
    }
}
