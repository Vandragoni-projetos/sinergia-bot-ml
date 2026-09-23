<?php

declare(strict_types=1);

namespace Sinergia\Integration\MercadoLivre\Http;

/**
 * Metadados de limite de requisições, SE o Mercado Livre os enviar.
 * A documentação oficial não publica limites numéricos nem nomes de cabeçalhos
 * (estudo F20); por isso registramos qualquer cabeçalho relacionado a limite.
 */
final readonly class RateLimitInfo
{
    /** @param array<string, string> $headers */
    public function __construct(
        public ?int $retryAfterSeconds,
        public array $headers,
    ) {
    }

    /** @param array<string, string> $allHeaders cabeçalhos já em minúsculas */
    public static function fromHeaders(array $allHeaders): self
    {
        $related = [];
        foreach ($allHeaders as $name => $value) {
            if (str_contains($name, 'ratelimit') || str_contains($name, 'rate-limit') || $name === 'retry-after') {
                $related[$name] = $value;
            }
        }

        $retryAfter = null;
        if (isset($allHeaders['retry-after']) && preg_match('/^\d{1,6}$/', trim($allHeaders['retry-after'])) === 1) {
            $retryAfter = (int) $allHeaders['retry-after'];
        }

        return new self($retryAfter, $related);
    }

    public function isEmpty(): bool
    {
        return $this->headers === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['retry_after_seconds' => $this->retryAfterSeconds, 'headers' => $this->headers];
    }
}
