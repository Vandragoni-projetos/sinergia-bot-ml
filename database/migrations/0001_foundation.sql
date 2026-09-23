-- SINERGIA BOT ML — 0001 fundação do MVP 0
-- Escopo: instalação, credencial OAuth do Mercado Livre (cifrada), estados OAuth,
-- cache de categorias oficiais, execuções de descoberta/validação e suas entradas.
-- Convenções: InnoDB, utf8mb4, timestamps DATETIME(3) em UTC (a conexão usa time_zone '+00:00'),
-- toda tabela de cliente tem installation_id + UNIQUE(installation_id, id) para FKs compostas.

CREATE TABLE IF NOT EXISTS installations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(64)     NOT NULL,
    name        VARCHAR(120)    NOT NULL,
    status      VARCHAR(16)     NOT NULL DEFAULT 'active',
    timezone    VARCHAR(64)     NOT NULL DEFAULT 'America/Sao_Paulo',
    site_id     CHAR(3)         NOT NULL DEFAULT 'MLB',
    plan_code   VARCHAR(32)     NULL,
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_installations_slug (slug),
    CONSTRAINT ck_installations_status CHECK (status IN ('active', 'suspended')),
    CONSTRAINT ck_installations_site CHECK (site_id REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conexão OAuth da instalação com o Mercado Livre. Tokens SEMPRE cifrados (nonce || ciphertext).
CREATE TABLE IF NOT EXISTS ml_credentials (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_id      BIGINT UNSIGNED NOT NULL,
    client_id            VARCHAR(32)     NOT NULL,
    ml_user_id           BIGINT UNSIGNED NULL,
    scopes               VARCHAR(255)    NULL,
    access_token_enc     VARBINARY(2048) NOT NULL,
    refresh_token_enc    VARBINARY(2048) NULL,
    key_id               VARCHAR(16)     NOT NULL,
    access_expires_at    DATETIME(3)     NOT NULL,
    refresh_obtained_at  DATETIME(3)     NULL,
    status               VARCHAR(16)     NOT NULL DEFAULT 'connected',
    last_refresh_at      DATETIME(3)     NULL,
    last_api_call_at     DATETIME(3)     NULL,
    version              INT UNSIGNED    NOT NULL DEFAULT 1,
    created_at           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_ml_credentials_installation (installation_id),
    UNIQUE KEY uq_ml_credentials_inst_id (installation_id, id),
    CONSTRAINT fk_ml_credentials_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT ck_ml_credentials_status CHECK (status IN ('connected', 'expired', 'revoked', 'error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fluxo OAuth em andamento: guarda só o HASH do state e o code_verifier CIFRADO; expira rápido.
CREATE TABLE IF NOT EXISTS ml_oauth_states (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_id    BIGINT UNSIGNED NOT NULL,
    state_hash         CHAR(64)        NOT NULL,
    code_verifier_enc  VARBINARY(512)  NULL,
    expires_at         DATETIME(3)     NOT NULL,
    created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_ml_oauth_states_hash (state_hash),
    KEY ix_ml_oauth_states_expires (expires_at),
    CONSTRAINT fk_ml_oauth_states_installation FOREIGN KEY (installation_id) REFERENCES installations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache GLOBAL da árvore oficial de categorias (dado público de referência, sem dado de cliente).
CREATE TABLE IF NOT EXISTS ml_categories (
    site_id             CHAR(3)          NOT NULL,
    category_id         VARCHAR(32)      NOT NULL,
    name                VARCHAR(255)     NOT NULL,
    parent_category_id  VARCHAR(32)      NULL,
    path_json           JSON             NOT NULL,
    children_json       JSON             NOT NULL,
    is_leaf             TINYINT(1)       NOT NULL,
    catalog_domain      VARCHAR(80)      NULL,
    total_items         BIGINT UNSIGNED  NULL,
    fetched_at          DATETIME(3)      NOT NULL,
    PRIMARY KEY (site_id, category_id),
    KEY ix_ml_categories_parent (site_id, parent_category_id),
    KEY ix_ml_categories_leaf (site_id, is_leaf),
    CONSTRAINT ck_ml_categories_id CHECK (category_id REGEXP '^[A-Z]{3}[0-9]+$'),
    CONSTRAINT ck_ml_categories_site CHECK (site_id REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada execução técnica (validação de categoria / de highlights) é identificável e auditável.
CREATE TABLE IF NOT EXISTS discovery_runs (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_id        BIGINT UNSIGNED NOT NULL,
    correlation_id         CHAR(36)        NOT NULL,
    source                 VARCHAR(40)     NOT NULL,
    site_id                CHAR(3)         NOT NULL,
    category_id            VARCHAR(32)     NULL,
    filter_attribute       VARCHAR(64)     NULL,
    filter_value           VARCHAR(64)     NULL,
    auth_mode              VARCHAR(16)     NOT NULL,
    status                 VARCHAR(16)     NOT NULL DEFAULT 'running',
    started_at             DATETIME(3)     NOT NULL,
    finished_at            DATETIME(3)     NULL,
    http_status            SMALLINT UNSIGNED NULL,
    items_found            INT UNSIGNED    NULL,
    duration_ms            INT UNSIGNED    NULL,
    error_code             VARCHAR(64)     NULL,
    error_message          VARCHAR(500)    NULL,
    rate_limit_json        JSON            NULL,
    response_headers_json  JSON            NULL,
    warnings_json          JSON            NULL,
    evidence_file          VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_discovery_runs_correlation (correlation_id),
    UNIQUE KEY uq_discovery_runs_inst_id (installation_id, id),
    KEY ix_discovery_runs_started (installation_id, started_at),
    CONSTRAINT fk_discovery_runs_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT ck_discovery_runs_source CHECK (source IN ('highlights_validation', 'category_validation')),
    CONSTRAINT ck_discovery_runs_status CHECK (status IN ('running', 'succeeded', 'failed')),
    CONSTRAINT ck_discovery_runs_auth CHECK (auth_mode IN ('none', 'oauth')),
    CONSTRAINT ck_discovery_runs_filter CHECK ((filter_attribute IS NULL) = (filter_value IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entradas devolvidas por um highlight: posição + ID + tipo, exatamente como o ML retornou.
-- FK composta com installation_id: uma entrada nunca pode apontar para execução de outra instalação.
CREATE TABLE IF NOT EXISTS discovery_run_entries (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    installation_id   BIGINT UNSIGNED  NOT NULL,
    discovery_run_id  BIGINT UNSIGNED  NOT NULL,
    position          SMALLINT UNSIGNED NOT NULL,
    ml_entity_id      VARCHAR(32)      NOT NULL,
    ml_entity_type    VARCHAR(24)      NULL,
    created_at        DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY ix_discovery_run_entries_run (discovery_run_id, position),
    KEY ix_discovery_run_entries_entity (installation_id, ml_entity_id),
    CONSTRAINT fk_discovery_run_entries_run FOREIGN KEY (installation_id, discovery_run_id)
        REFERENCES discovery_runs (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_discovery_run_entries_position CHECK (position >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
