-- SINERGIA BOT ML — 0008 conexão WhatsApp por conta (F1, etapa 5)
-- Uma linha por installation_id (PK): no máximo UMA instância do provedor por conta.
-- O token da instância fica cifrado (SecretBox/APP_KEY). QR code e código de pareamento NUNCA são gravados:
-- são efêmeros e sempre vêm da consulta ao provedor no momento da exibição.

CREATE TABLE IF NOT EXISTS whatsapp_connections (
    installation_id      BIGINT UNSIGNED NOT NULL,
    provider             VARCHAR(16)     NOT NULL,
    instance_name        VARCHAR(64)     NOT NULL,
    provider_instance_id VARCHAR(64)     NULL,
    instance_token_enc   TEXT            NULL,
    key_id               VARCHAR(16)     NULL,
    status               VARCHAR(16)     NOT NULL DEFAULT 'new',
    connect_mode         VARCHAR(8)      NULL,
    connect_started_at   DATETIME(3)     NULL,
    connected_at         DATETIME(3)     NULL,
    phone_display        VARCHAR(32)     NULL,
    profile_name         VARCHAR(120)    NULL,
    last_error_code      VARCHAR(32)     NULL,
    last_error_at        DATETIME(3)     NULL,
    status_checked_at    DATETIME(3)     NULL,
    created_at           DATETIME(3)     NOT NULL,
    updated_at           DATETIME(3)     NOT NULL,
    updated_by_user_id   BIGINT UNSIGNED NULL,
    PRIMARY KEY (installation_id),
    UNIQUE KEY uq_whatsapp_connections_instance_name (provider, instance_name),
    CONSTRAINT fk_whatsapp_connections_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT fk_whatsapp_connections_user FOREIGN KEY (installation_id, updated_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT ck_whatsapp_connections_status CHECK (status IN ('new', 'disconnected', 'connecting', 'connected', 'hibernated')),
    CONSTRAINT ck_whatsapp_connections_mode CHECK (connect_mode IS NULL OR connect_mode IN ('qr', 'paircode')),
    CONSTRAINT ck_whatsapp_connections_token CHECK ((instance_token_enc IS NULL) = (key_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
