-- SINERGIA BOT ML — 0007 catálogo de produtos MLB e seletor de ofertas (F1, etapa 4)
-- PÚBLICO (sem installation_id, compartilhável): ml_ranking_snapshots, ml_ranking_entries, ml_products, ml_product_offers.
--   Só dados do catálogo oficial necessários para a oferta; nada de preferência, filtro ou escolha de conta.
-- PRIVADO (por installation_id): offer_selection_runs e account_offer_candidates.

CREATE TABLE IF NOT EXISTS ml_ranking_snapshots (
    site_id         VARCHAR(8)        NOT NULL,
    ml_category_id  VARCHAR(32)       NOT NULL,
    entries         SMALLINT UNSIGNED NOT NULL,
    fetched_at      DATETIME(3)       NOT NULL,
    PRIMARY KEY (site_id, ml_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ml_ranking_entries (
    site_id         VARCHAR(8)        NOT NULL,
    ml_category_id  VARCHAR(32)       NOT NULL,
    position        SMALLINT UNSIGNED NOT NULL,
    ml_id           VARCHAR(32)       NOT NULL,
    entry_type      VARCHAR(16)       NULL,
    PRIMARY KEY (site_id, ml_category_id, position),
    KEY ix_ml_ranking_entries_ml_id (ml_id),
    CONSTRAINT fk_ml_ranking_entries_snapshot FOREIGN KEY (site_id, ml_category_id)
        REFERENCES ml_ranking_snapshots (site_id, ml_category_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: ok | not_found | forbidden (cache negativo evita repetir chamadas que falham).
CREATE TABLE IF NOT EXISTS ml_products (
    ml_product_id   VARCHAR(32)       NOT NULL,
    site_id         VARCHAR(8)        NOT NULL,
    status          VARCHAR(16)       NOT NULL,
    name            VARCHAR(255)      NULL,
    domain_id       VARCHAR(96)       NULL,
    permalink       VARCHAR(512)      NULL,
    picture_url     VARCHAR(512)      NULL,
    pictures_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    catalog_status  VARCHAR(32)       NULL,
    fetched_at      DATETIME(3)       NOT NULL,
    PRIMARY KEY (ml_product_id),
    CONSTRAINT ck_ml_products_id CHECK (ml_product_id REGEXP '^MLB[0-9]+$'),
    CONSTRAINT ck_ml_products_status CHECK (status IN ('ok', 'not_found', 'forbidden'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Só a oferta ESCOLHIDA pela regra determinística (não guarda a lista inteira de anúncios).
CREATE TABLE IF NOT EXISTS ml_product_offers (
    ml_product_id        VARCHAR(32)       NOT NULL,
    status               VARCHAR(16)       NOT NULL,
    item_id              VARCHAR(32)       NULL,
    price                DECIMAL(12,2)     NULL,
    original_price       DECIMAL(12,2)     NULL,
    currency_id          CHAR(3)           NULL,
    discount_pct         TINYINT UNSIGNED  NULL,
    selection_rule       VARCHAR(16)       NULL,
    free_shipping        TINYINT(1)        NULL,
    official_store       TINYINT(1)        NULL,
    offers_total         SMALLINT UNSIGNED NULL,
    offers_eligible      SMALLINT UNSIGNED NULL,
    fetched_at           DATETIME(3)       NOT NULL,
    PRIMARY KEY (ml_product_id),
    CONSTRAINT fk_ml_product_offers_product FOREIGN KEY (ml_product_id) REFERENCES ml_products (ml_product_id) ON DELETE CASCADE,
    CONSTRAINT ck_ml_product_offers_status CHECK (status IN ('ok', 'no_offer')),
    CONSTRAINT ck_ml_product_offers_ok CHECK (
        status <> 'ok' OR (item_id IS NOT NULL AND price > 0 AND currency_id = 'BRL' AND selection_rule IN ('buy_box', 'lowest_price'))
    ),
    CONSTRAINT ck_ml_product_offers_original CHECK (original_price IS NULL OR original_price > price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_selection_runs (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    installation_id BIGINT UNSIGNED   NOT NULL,
    status          VARCHAR(16)       NOT NULL DEFAULT 'running',
    api_calls       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    stats_json      TEXT              NULL,
    started_at      DATETIME(3)       NOT NULL,
    finished_at     DATETIME(3)       NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_offer_selection_runs_inst_id (installation_id, id),
    CONSTRAINT fk_offer_selection_runs_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT ck_offer_selection_runs_status CHECK (status IN ('running', 'completed', 'partial', 'auth_failed', 'no_selection'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot do preço no momento da seleção (a etapa 8 revalida antes de enviar).
CREATE TABLE IF NOT EXISTS account_offer_candidates (
    installation_id   BIGINT UNSIGNED   NOT NULL,
    run_id            BIGINT UNSIGNED   NOT NULL,
    ml_product_id     VARCHAR(32)       NOT NULL,
    niche_id          INT UNSIGNED      NOT NULL,
    subniche_id       INT UNSIGNED      NOT NULL,
    ml_category_id    VARCHAR(32)       NOT NULL,
    ranking_position  SMALLINT UNSIGNED NOT NULL,
    sort_order        SMALLINT UNSIGNED NOT NULL,
    item_id           VARCHAR(32)       NOT NULL,
    price             DECIMAL(12,2)     NOT NULL,
    original_price    DECIMAL(12,2)     NULL,
    discount_pct      TINYINT UNSIGNED  NOT NULL,
    selection_rule    VARCHAR(16)       NOT NULL,
    PRIMARY KEY (installation_id, run_id, ml_product_id),
    CONSTRAINT fk_account_offer_candidates_run FOREIGN KEY (installation_id, run_id)
        REFERENCES offer_selection_runs (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_account_offer_candidates_product FOREIGN KEY (ml_product_id) REFERENCES ml_products (ml_product_id),
    CONSTRAINT fk_account_offer_candidates_subniche FOREIGN KEY (niche_id, subniche_id) REFERENCES subniches (niche_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
