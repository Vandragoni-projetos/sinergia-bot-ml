<?php

declare(strict_types=1);

namespace Sinergia\Application\WhatsApp;

/**
 * O que o card WhatsApp mostra. qrCode/pairCode só existem no estado "waiting" e vêm da consulta
 * ao provedor feita NESTA requisição (nunca de dado gravado).
 */
final readonly class WhatsAppCard
{
    public const string UNAVAILABLE = 'unavailable';
    public const string NOT_CONNECTED = 'not_connected';
    public const string WAITING = 'waiting';
    public const string EXPIRED = 'expired';
    public const string CONNECTED = 'connected';
    public const string DISCONNECTED = 'disconnected';
    public const string ERROR = 'error';

    public function __construct(
        public string $state,
        public ?string $qrCode = null,
        public ?string $pairCode = null,
        public ?int $secondsLeft = null,
        public ?string $phoneDisplay = null,
        public ?string $profileName = null,
        public ?\DateTimeImmutable $connectedAt = null,
        public ?string $errorCode = null,
    ) {
    }
}
