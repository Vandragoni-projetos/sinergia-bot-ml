-- SINERGIA BOT ML — 0009 destinos (F1, etapa 6)
-- whatsapp_available_destinations: o que a ÚLTIMA sincronização encontrou no WhatsApp DA CONTA (grupos e canais).
-- destinations: destinos cadastrados pela conta, com fatos técnicos separados das declarações do cliente.
-- A conexão WhatsApp é única por conta (whatsapp_connections.installation_id), então installation_id a identifica.
-- public_key: identificador aleatório usado nas URLs/formulários (o JID e o id interno nunca vão para o HTML).

CREATE TABLE IF NOT EXISTS whatsapp_available_destinations (
    installation_id      BIGINT UNSIGNED  NOT NULL,
    provider_ref         VARCHAR(96)      NOT NULL,
    pick_key             CHAR(20)         NOT NULL,
    type                 VARCHAR(8)       NOT NULL,
    name                 VARCHAR(120)     NOT NULL,
    tech_we_are_admin    TINYINT(1)       NULL,
    tech_join_approval   TINYINT(1)       NULL,
    tech_announce_only   TINYINT(1)       NULL,
    tech_is_community    TINYINT(1)       NULL,
    participants         INT UNSIGNED     NULL,
    fetched_at           DATETIME(3)      NOT NULL,
    PRIMARY KEY (installation_id, provider_ref),
    UNIQUE KEY uq_whatsapp_available_pick (installation_id, pick_key),
    CONSTRAINT fk_whatsapp_available_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT ck_whatsapp_available_type CHECK (type IN ('channel', 'group'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS destinations (
    id                          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    installation_id             BIGINT UNSIGNED   NOT NULL,
    public_key                  CHAR(20)          NOT NULL,
    type                        VARCHAR(8)        NOT NULL,
    provider_ref                VARCHAR(96)       NOT NULL,
    name                        VARCHAR(120)      NOT NULL,
    niche_id                    INT UNSIGNED      NULL,
    mode                        VARCHAR(8)        NOT NULL DEFAULT 'manual',
    window_start                TIME              NOT NULL DEFAULT '08:00:00',
    window_end                  TIME              NOT NULL DEFAULT '22:00:00',
    interval_minutes            SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    user_paused                 TINYINT(1)        NOT NULL DEFAULT 1,
    status                      VARCHAR(12)       NOT NULL DEFAULT 'ineligible',
    eligibility                 VARCHAR(24)       NOT NULL DEFAULT 'ineligible',
    ineligible_reason           VARCHAR(40)       NULL,
    -- Fatos técnicos (lidos do WhatsApp; NULL = desconhecido)
    tech_checked_at             DATETIME(3)       NULL,
    tech_present                TINYINT(1)        NOT NULL DEFAULT 1,
    tech_is_channel             TINYINT(1)        NOT NULL,
    tech_we_are_admin           TINYINT(1)        NULL,
    tech_has_invite_link        TINYINT(1)        NULL,
    tech_join_approval          TINYINT(1)        NULL,
    tech_announce_only          TINYINT(1)        NULL,
    tech_is_community           TINYINT(1)        NULL,
    tech_blocking_reason        VARCHAR(40)       NULL,
    -- Declarações do cliente (registro, não prova)
    public_declared             TINYINT(1)        NOT NULL DEFAULT 0,
    public_declared_at          DATETIME(3)       NULL,
    public_declared_by_user_id  BIGINT UNSIGNED   NULL,
    public_declaration_version  VARCHAR(16)       NULL,
    media_registered_declared   TINYINT(1)        NOT NULL DEFAULT 0,
    media_declared_at           DATETIME(3)       NULL,
    media_declared_by_user_id   BIGINT UNSIGNED   NULL,
    media_declaration_version   VARCHAR(16)       NULL,
    last_test_sent_at           DATETIME(3)       NULL,
    last_sent_at                DATETIME(3)       NULL,
    created_at                  DATETIME(3)       NOT NULL,
    created_by_user_id          BIGINT UNSIGNED   NOT NULL,
    updated_at                  DATETIME(3)       NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_destinations_inst_id (installation_id, id),
    UNIQUE KEY uq_destinations_inst_ref (installation_id, provider_ref),
    UNIQUE KEY uq_destinations_public_key (public_key),
    CONSTRAINT fk_destinations_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT fk_destinations_niche FOREIGN KEY (niche_id) REFERENCES niches (id),
    CONSTRAINT fk_destinations_creator FOREIGN KEY (installation_id, created_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT fk_destinations_public_by FOREIGN KEY (installation_id, public_declared_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT fk_destinations_media_by FOREIGN KEY (installation_id, media_declared_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT ck_destinations_type CHECK (type IN ('channel', 'group')),
    CONSTRAINT ck_destinations_ref CHECK (
        (type = 'channel' AND provider_ref LIKE '%@newsletter' AND tech_is_channel = 1)
        OR (type = 'group' AND provider_ref LIKE '%@g.us' AND tech_is_channel = 0)
    ),
    CONSTRAINT ck_destinations_mode CHECK (mode IN ('auto', 'manual')),
    CONSTRAINT ck_destinations_status CHECK (status IN ('active', 'paused', 'ineligible')),
    CONSTRAINT ck_destinations_eligibility CHECK (eligibility IN ('channel_public', 'group_declared_public', 'ineligible')),
    -- Nunca "ativo" sem ser elegível; nunca "grupo declarado público" sem as duas declarações e sem bloqueio técnico.
    CONSTRAINT ck_destinations_active CHECK (status <> 'active' OR eligibility <> 'ineligible'),
    CONSTRAINT ck_destinations_group_label CHECK (
        eligibility <> 'group_declared_public'
        OR (type = 'group' AND public_declared = 1 AND media_registered_declared = 1 AND tech_blocking_reason IS NULL)
    ),
    CONSTRAINT ck_destinations_channel_label CHECK (eligibility <> 'channel_public' OR type = 'channel'),
    CONSTRAINT ck_destinations_window CHECK (window_start < window_end),
    CONSTRAINT ck_destinations_interval CHECK (interval_minutes BETWEEN 10 AND 720)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subnichos do destino: sempre do nicho do destino E ativados pela própria conta
-- (desativar o subnicho na tela Nichos o remove daqui).
CREATE TABLE IF NOT EXISTS destination_subniches (
    installation_id BIGINT UNSIGNED NOT NULL,
    destination_id  BIGINT UNSIGNED NOT NULL,
    niche_id        INT UNSIGNED    NOT NULL,
    subniche_id     INT UNSIGNED    NOT NULL,
    PRIMARY KEY (installation_id, destination_id, subniche_id),
    CONSTRAINT fk_destination_subniches_destination FOREIGN KEY (installation_id, destination_id)
        REFERENCES destinations (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_destination_subniches_account FOREIGN KEY (installation_id, subniche_id)
        REFERENCES account_subniches (installation_id, subniche_id) ON DELETE CASCADE,
    CONSTRAINT fk_destination_subniches_subniche FOREIGN KEY (niche_id, subniche_id) REFERENCES subniches (niche_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
