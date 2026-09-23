<?php

declare(strict_types=1);

namespace Sinergia\Application\Port\MercadoLivre;

/**
 * Contrato de falha exposto pelos adaptadores do Mercado Livre.
 * getMessage() é sempre sanitizada (sem token, secret, code ou Authorization).
 */
interface MercadoLivreFailure extends \Throwable
{
    /** Código interno estável (ex.: timeout, unauthorized, contract_violation). */
    public function failureCode(): string;

    public function failureHttpStatus(): ?int;

    /** Campo "error" oficial devolvido pelo Mercado Livre, quando houver. */
    public function failureMlError(): ?string;

    /** @return array<string, string> cabeçalhos não sensíveis */
    public function failureHeaders(): array;

    /** @return array<string, mixed>|null */
    public function failureRateLimit(): ?array;

    public function failureDurationMs(): ?int;
}
