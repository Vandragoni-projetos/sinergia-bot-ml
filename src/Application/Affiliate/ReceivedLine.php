<?php

declare(strict_types=1);

namespace Sinergia\Application\Affiliate;

/** Uma linha colada pelo cliente. raw = texto EXATO da linha; url = raw sem espaços nas pontas (bytes do link intactos). */
final readonly class ReceivedLine
{
    public const string VALID = 'valid';
    public const string INVALID = 'invalid';
    public const string INVALID_DOMAIN = 'invalid_domain';
    public const string EMPTY = 'empty';
    public const string DUPLICATE = 'duplicate';

    public const string EVIDENCE_PRODUCT_ID = 'product_id';
    public const string EVIDENCE_POSITION = 'position_only';
    public const string EVIDENCE_CONFLICT = 'conflict';
    public const string EVIDENCE_NONE = 'none';

    public function __construct(
        public int $lineNo,
        public string $raw,
        public string $url,
        public string $formatStatus,
        public ?string $detectedProductId,
        public string $evidence,
        public ?int $proposedPosition,
    ) {
    }

    public function isValid(): bool
    {
        return $this->formatStatus === self::VALID;
    }
}
