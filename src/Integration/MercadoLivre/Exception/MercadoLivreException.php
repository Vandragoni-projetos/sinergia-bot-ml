<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Exception;

use Sinergia\Application\Port\MercadoLivre\MercadoLivreFailure;
use Sinergia\Integration\MercadoLivre\Http\RateLimitInfo;
use Sinergia\Shared\Security\Redactor;

/**
 * Base de todos os erros da integração. A mensagem é SEMPRE sanitizada:
 * nunca contém token, secret, code ou cabeçalho Authorization.
 * Exposta à aplicação apenas pelo contrato MercadoLivreFailure.
 */
class MercadoLivreException extends \RuntimeException implements MercadoLivreFailure
{
    /** @param array<string, string> $headers */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        public readonly ?string $path = null,
        public readonly ?string $mlError = null,
        public readonly array $headers = [],
        public readonly ?RateLimitInfo $rateLimit = null,
        public readonly ?int $durationMs = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(Redactor::redactText($message), 0, $previous);
    }

    public function failureCode(): string
    {
        return $this->errorCode;
    }

    public function failureHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function failureMlError(): ?string
    {
        return $this->mlError;
    }

    public function failureHeaders(): array
    {
        return $this->headers;
    }

    public function failureRateLimit(): ?array
    {
        return $this->rateLimit?->toArray();
    }

    public function failureDurationMs(): ?int
    {
        return $this->durationMs;
    }
}
