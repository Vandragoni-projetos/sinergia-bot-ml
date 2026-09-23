<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Http;

use Sinergia\Application\Port\MercadoLivre\AuthMode;
use Sinergia\Application\Port\MercadoLivre\ResponseMeta;

/** Resposta bem-sucedida (2xx) já validada como JSON. Uso interno do adaptador. */
final readonly class ApiResponse
{
    /**
     * @param array<string, string> $headers cabeçalhos não sensíveis (lista permitida)
     * @param array<array-key, mixed> $json
     */
    public function __construct(
        public string $method,
        public string $path,
        public int $status,
        public array $headers,
        public array $json,
        public string $rawBody,
        public int $durationMs,
        public RateLimitInfo $rateLimit,
        public AuthMode $authMode,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }

    /** Converte para o tipo neutro da porta (o que a aplicação pode registrar). */
    public function meta(): ResponseMeta
    {
        return new ResponseMeta(
            $this->method,
            $this->path,
            $this->status,
            $this->headers,
            $this->rateLimit->toArray(),
            $this->json,
            $this->durationMs,
            $this->authMode,
            $this->fetchedAt,
        );
    }
}
