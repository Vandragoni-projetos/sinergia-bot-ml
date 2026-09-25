-- SINERGIA BOT ML — 0005 nichos (F1, etapa 3)
-- Catálogo GLOBAL administrado pela Sinergia: niches → subniches → subniche_categories (categorias ML curadas).
-- Preferências PRIVADAS de cada conta: account_niches (filtros por nicho) e account_subniches (subnichos ativados).
-- O cliente nunca escreve no catálogo global e nunca vê os IDs de categoria do Mercado Livre.

CREATE TABLE IF NOT EXISTS niches (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(64)      NOT NULL,
    name       VARCHAR(80)      NOT NULL,
    sort       SMALLINT         NOT NULL DEFAULT 0,
    active     TINYINT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_niches_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subniches (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    niche_id   INT UNSIGNED     NOT NULL,
    slug       VARCHAR(64)      NOT NULL,
    name       VARCHAR(80)      NOT NULL,
    sort       SMALLINT         NOT NULL DEFAULT 0,
    active     TINYINT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subniches_niche_slug (niche_id, slug),
    UNIQUE KEY uq_subniches_niche_id (niche_id, id),
    CONSTRAINT fk_subniches_niche FOREIGN KEY (niche_id) REFERENCES niches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada categoria só entra como 'approved' depois da validação documentada (árvore oficial + amostra de produtos).
-- 'rejected' fica registrada para nunca ser reaproveitada por engano.
CREATE TABLE IF NOT EXISTS subniche_categories (
    subniche_id      INT UNSIGNED     NOT NULL,
    site_id          VARCHAR(8)       NOT NULL,
    ml_category_id   VARCHAR(32)      NOT NULL,
    status           VARCHAR(16)      NOT NULL,
    expected_domains VARCHAR(512)     NULL,
    sample_products  SMALLINT UNSIGNED NULL,
    sample_matching  SMALLINT UNSIGNED NULL,
    note             VARCHAR(255)     NULL,
    curation_version VARCHAR(16)      NOT NULL,
    validated_at     DATETIME(3)      NOT NULL,
    PRIMARY KEY (subniche_id, site_id, ml_category_id),
    KEY ix_subniche_categories_status (site_id, status),
    CONSTRAINT fk_subniche_categories_subniche FOREIGN KEY (subniche_id) REFERENCES subniches (id),
    CONSTRAINT ck_subniche_categories_status CHECK (status IN ('approved', 'rejected')),
    CONSTRAINT ck_subniche_categories_approved CHECK (
        status <> 'approved' OR (expected_domains IS NOT NULL AND sample_products >= 8 AND sample_matching * 100 >= sample_products * 85)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_niches (
    installation_id    BIGINT UNSIGNED  NOT NULL,
    niche_id           INT UNSIGNED     NOT NULL,
    min_discount_pct   TINYINT UNSIGNED NULL,
    min_price          DECIMAL(10,2)    NULL,
    max_price          DECIMAL(10,2)    NULL,
    require_photo      TINYINT(1)       NOT NULL DEFAULT 1,
    updated_at         DATETIME(3)      NOT NULL,
    updated_by_user_id BIGINT UNSIGNED  NOT NULL,
    PRIMARY KEY (installation_id, niche_id),
    CONSTRAINT fk_account_niches_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT fk_account_niches_niche FOREIGN KEY (niche_id) REFERENCES niches (id),
    CONSTRAINT fk_account_niches_user FOREIGN KEY (installation_id, updated_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT ck_account_niches_discount CHECK (min_discount_pct IS NULL OR min_discount_pct <= 90),
    CONSTRAINT ck_account_niches_prices CHECK (
        (min_price IS NULL OR min_price > 0) AND (max_price IS NULL OR max_price > 0)
        AND (min_price IS NULL OR max_price IS NULL OR max_price >= min_price)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Presença da linha = subnicho ativado. As FKs compostas garantem que o subnicho pertence ao nicho
-- e que o nicho tem uma linha de filtros DA MESMA conta.
CREATE TABLE IF NOT EXISTS account_subniches (
    installation_id    BIGINT UNSIGNED  NOT NULL,
    niche_id           INT UNSIGNED     NOT NULL,
    subniche_id        INT UNSIGNED     NOT NULL,
    enabled_at         DATETIME(3)      NOT NULL,
    enabled_by_user_id BIGINT UNSIGNED  NOT NULL,
    PRIMARY KEY (installation_id, subniche_id),
    KEY ix_account_subniches_niche (installation_id, niche_id),
    CONSTRAINT fk_account_subniches_account_niche FOREIGN KEY (installation_id, niche_id)
        REFERENCES account_niches (installation_id, niche_id) ON DELETE CASCADE,
    CONSTRAINT fk_account_subniches_subniche FOREIGN KEY (niche_id, subniche_id) REFERENCES subniches (niche_id, id),
    CONSTRAINT fk_account_subniches_user FOREIGN KEY (installation_id, enabled_by_user_id) REFERENCES users (installation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
