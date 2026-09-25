-- SINERGIA BOT ML — 0010 biblioteca de links de afiliado e lotes (F1, etapa 7)
-- Fluxo manual_batch: produtos aguardando link → Copiar URLs (lote exportado) → Gerador oficial do ML →
-- Colar links (PRÉ-VISUALIZAÇÃO, nada gravado na biblioteca) → confirmação explícita → affiliate_links.
-- O link é guardado EXATAMENTE como recebido (colunas utf8mb4_bin) e NUNCA é aberto pela aplicação.
-- public_key/item_key: identificadores aleatórios usados em formulários (ids internos não vão para o HTML).

CREATE TABLE IF NOT EXISTS affiliate_link_batches (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    installation_id     BIGINT UNSIGNED   NOT NULL,
    public_key          CHAR(20)          NOT NULL,
    created_by_user_id  BIGINT UNSIGNED   NOT NULL,
    status              VARCHAR(20)       NOT NULL DEFAULT 'exported',
    item_count          SMALLINT UNSIGNED NOT NULL,
    received_count      SMALLINT UNSIGNED NULL,
    confirmed_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    anomalies           VARCHAR(255)      NULL,
    exported_at         DATETIME(3)       NOT NULL,
    pasted_at           DATETIME(3)       NULL,
    confirmed_at        DATETIME(3)       NULL,
    confirmed_by_user_id BIGINT UNSIGNED  NULL,
    expires_at          DATETIME(3)       NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_affiliate_link_batches_inst_id (installation_id, id),
    UNIQUE KEY uq_affiliate_link_batches_key (public_key),
    KEY ix_affiliate_link_batches_open (installation_id, status),
    CONSTRAINT fk_affiliate_link_batches_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT fk_affiliate_link_batches_user FOREIGN KEY (installation_id, created_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT fk_affiliate_link_batches_confirmer FOREIGN KEY (installation_id, confirmed_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT ck_affiliate_link_batches_status CHECK (status IN ('exported', 'preview', 'confirmed', 'partially_confirmed', 'cancelled', 'expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um item por produto exportado, na ordem exportada. received_raw guarda a linha associada, sem alteração.
CREATE TABLE IF NOT EXISTS affiliate_link_batch_items (
    id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    installation_id   BIGINT UNSIGNED   NOT NULL,
    batch_id          BIGINT UNSIGNED   NOT NULL,
    item_key          CHAR(20)          NOT NULL,
    position          SMALLINT UNSIGNED NOT NULL,
    ml_product_id     VARCHAR(32)       NOT NULL,
    original_url      VARCHAR(512)      COLLATE utf8mb4_bin NOT NULL,
    received_raw      TEXT              COLLATE utf8mb4_bin NULL,
    received_position SMALLINT UNSIGNED NULL,
    format_status     VARCHAR(16)       NULL,
    match_evidence    VARCHAR(16)       NOT NULL DEFAULT 'none',
    match_status      VARCHAR(12)       NOT NULL DEFAULT 'unmatched',
    reason            VARCHAR(64)       NULL,
    confirmed_at      DATETIME(3)       NULL,
    affiliate_link_id BIGINT UNSIGNED   NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_affiliate_link_batch_items_pos (installation_id, batch_id, position),
    UNIQUE KEY uq_affiliate_link_batch_items_product (installation_id, batch_id, ml_product_id),
    UNIQUE KEY uq_affiliate_link_batch_items_key (item_key),
    CONSTRAINT fk_affiliate_link_batch_items_batch FOREIGN KEY (installation_id, batch_id)
        REFERENCES affiliate_link_batches (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_affiliate_link_batch_items_format CHECK (format_status IS NULL OR format_status IN ('valid', 'invalid', 'invalid_domain', 'empty', 'duplicate')),
    CONSTRAINT ck_affiliate_link_batch_items_evidence CHECK (match_evidence IN ('product_id', 'position_only', 'manual', 'none')),
    CONSTRAINT ck_affiliate_link_batch_items_status CHECK (match_status IN ('unmatched', 'proposed', 'confirmed', 'rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Todas as linhas coladas, EXATAMENTE como vieram (inclusive as que não viraram associação).
CREATE TABLE IF NOT EXISTS affiliate_link_batch_lines (
    installation_id     BIGINT UNSIGNED   NOT NULL,
    batch_id            BIGINT UNSIGNED   NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    received_raw        TEXT              COLLATE utf8mb4_bin NOT NULL,
    format_status       VARCHAR(16)       NOT NULL,
    detected_product_id VARCHAR(32)       NULL,
    evidence            VARCHAR(16)       NOT NULL DEFAULT 'none',
    proposed_position   SMALLINT UNSIGNED NULL,
    PRIMARY KEY (installation_id, batch_id, line_no),
    CONSTRAINT fk_affiliate_link_batch_lines_batch FOREIGN KEY (installation_id, batch_id)
        REFERENCES affiliate_link_batches (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_affiliate_link_batch_lines_format CHECK (format_status IN ('valid', 'invalid', 'invalid_domain', 'empty', 'duplicate')),
    CONSTRAINT ck_affiliate_link_batch_lines_evidence CHECK (evidence IN ('product_id', 'position_only', 'conflict', 'none'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Biblioteca: no máximo UM link 'active' por (conta, produto) — garantido pelo índice único na coluna gerada.
CREATE TABLE IF NOT EXISTS affiliate_links (
    id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    installation_id       BIGINT UNSIGNED  NOT NULL,
    site_id               CHAR(3)          NOT NULL,
    ml_product_id         VARCHAR(32)      NOT NULL,
    original_url          VARCHAR(512)     COLLATE utf8mb4_bin NOT NULL,
    affiliate_url         VARCHAR(2048)    COLLATE utf8mb4_bin NOT NULL,
    affiliate_url_sha256  CHAR(64)         NOT NULL,
    source                VARCHAR(16)      NOT NULL,
    batch_id              BIGINT UNSIGNED  NULL,
    batch_item_id         BIGINT UNSIGNED  NULL,
    status                VARCHAR(12)      NOT NULL DEFAULT 'active',
    active_product_id     VARCHAR(32)      AS (IF(status = 'active', ml_product_id, NULL)) PERSISTENT,
    received_at           DATETIME(3)      NOT NULL,
    confirmed_at          DATETIME(3)      NOT NULL,
    confirmed_by_user_id  BIGINT UNSIGNED  NOT NULL,
    first_used_at         DATETIME(3)      NULL,
    last_used_at          DATETIME(3)      NULL,
    use_count             INT UNSIGNED     NOT NULL DEFAULT 0,
    replaced_at           DATETIME(3)      NULL,
    invalidated_at        DATETIME(3)      NULL,
    invalid_reason        VARCHAR(64)      NULL,
    created_at            DATETIME(3)      NOT NULL,
    updated_at            DATETIME(3)      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_affiliate_links_inst_id (installation_id, id),
    UNIQUE KEY uq_affiliate_links_one_active (installation_id, active_product_id),
    KEY ix_affiliate_links_lookup (installation_id, ml_product_id, status),
    CONSTRAINT fk_affiliate_links_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT fk_affiliate_links_confirmer FOREIGN KEY (installation_id, confirmed_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT fk_affiliate_links_batch FOREIGN KEY (installation_id, batch_id) REFERENCES affiliate_link_batches (installation_id, id),
    CONSTRAINT ck_affiliate_links_status CHECK (status IN ('active', 'replaced', 'invalid')),
    CONSTRAINT ck_affiliate_links_source CHECK (source IN ('manual_batch', 'official_api')),
    CONSTRAINT ck_affiliate_links_product CHECK (ml_product_id REGEXP '^MLB[0-9]+$'),
    CONSTRAINT ck_affiliate_links_sha CHECK (affiliate_url_sha256 = SHA2(affiliate_url, 256))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
