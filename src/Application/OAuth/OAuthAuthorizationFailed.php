<?php

declare(strict_types=1);

namespace Sinergia\Application\OAuth;

/**
 * Falha ao concluir o OAuth. A mensagem é segura para exibir ao usuário:
 * nunca contém code, state, tokens, client_secret ou APP_KEY.
 */
final class OAuthAuthorizationFailed extends \RuntimeException
{
    public const string MISSING_PARAMETERS = 'missing_parameters';
    public const string AUTHORIZATION_DENIED = 'authorization_denied';
    public const string STATE_UNKNOWN = 'state_unknown';
    public const string STATE_EXPIRED = 'state_expired';
    public const string TOKEN_EXCHANGE_FAILED = 'token_exchange_failed';
    public const string STORAGE_FAILED = 'storage_failed';
    public const string LOGIN_REQUIRED = 'login_required';
    public const string ACCOUNT_MISMATCH = 'account_mismatch';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $providerError = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
