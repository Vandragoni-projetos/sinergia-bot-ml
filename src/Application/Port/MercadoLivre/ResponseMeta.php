<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/**
 * Metadados neutros de uma resposta bem-sucedida do Mercado Livre, para registro e evidência.
 * Não contém tokens nem cabeçalhos sensíveis (só a lista permitida pelo adaptador).
 */
final readonly class ResponseMeta
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $rateLimit
     * @param array<array-key, mixed> $body corpo JSON decodificado
     */
    public function __construct(
        public string $method,
        public string $path,
        public int $status,
        public array $headers,
        public array $rateLimit,
        public array $body,
        public int $durationMs,
        public AuthMode $authMode,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }
}
