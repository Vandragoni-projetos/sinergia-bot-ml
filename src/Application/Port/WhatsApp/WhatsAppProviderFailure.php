<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\WhatsApp;

/**
 * Falha do provedor de WhatsApp. A mensagem é sempre genérica: nunca contém token, admintoken,
 * URL com credencial nem o texto devolvido pelo provedor.
 */
final class WhatsAppProviderFailure extends \RuntimeException
{
    public const string UNAUTHORIZED = 'unauthorized';
    public const string FORBIDDEN = 'forbidden';
    public const string NOT_FOUND = 'not_found';
    public const string CONFLICT = 'conflict';
    public const string RATE_LIMITED = 'rate_limited';
    public const string SERVER_ERROR = 'server_error';
    public const string TIMEOUT = 'timeout';
    public const string NETWORK_ERROR = 'network_error';
    public const string INVALID_RESPONSE = 'invalid_response';
    public const string HTTP_ERROR = 'http_error';

    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly string $operation = '',
    ) {
        parent::__construct(sprintf('Falha do provedor de WhatsApp em %s: %s%s.', $operation ?: 'operação', $errorCode, $httpStatus === null ? '' : ' (HTTP ' . $httpStatus . ')'));
    }
}
