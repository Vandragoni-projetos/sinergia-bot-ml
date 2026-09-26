-- SINERGIA BOT ML — 0011 fila, planejamento, worker e publicação (F1, etapa 8)
-- installations.bot_status: interruptor global por conta. Nasce 'paused' (o cliente ativa; a etapa 9 faz isso no onboarding).
-- dispatch_queue: UNIQUE(installation_id, destination_id, ml_product_id) = um produto NUNCA se repete no mesmo destino.
-- dispatch_attempts: uma linha por tentativa de envio (resultado, erro tipado, horário). Nunca guarda token.
-- worker_heartbeats: sinal de vida de cada processo bot:worker.

ALTER TABLE installations
    ADD COLUMN IF NOT EXISTS bot_status VARCHAR(8) NOT NULL DEFAULT 'paused',
    ADD COLUMN IF NOT EXISTS bot_status_changed_at DATETIME(3) NULL,
    ADD COLUMN IF NOT EXISTS bot_status_changed_by BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS destinations_revalidated_at DATETIME(3) NULL,
    ADD CONSTRAINT IF NOT EXISTS ck_installations_bot_status CHECK (bot_status IN ('active', 'paused'));

CREATE TABLE IF NOT EXISTS dispatch_queue (
    id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    installation_id     BIGINT UNSIGNED   NOT NULL,
    public_key          CHAR(20)          NOT NULL,
    destination_id      BIGINT UNSIGNED   NOT NULL,
    niche_id            INT UNSIGNED      NOT NULL,
    subniche_id         INT UNSIGNED      NOT NULL,
    ml_product_id       VARCHAR(32)       NOT NULL,
    status              VARCHAR(24)       NOT NULL,
    affiliate_link_id   BIGINT UNSIGNED   NULL,
    scheduled_for       DATETIME(3)       NOT NULL,
    next_attempt_at     DATETIME(3)       NULL,
    attempts            TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    planned_price       DECIMAL(12,2)     NOT NULL,
    planned_original    DECIMAL(12,2)     NULL,
    planned_discount    TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    claim_token         CHAR(32)          NULL,
    claimed_at          DATETIME(3)       NULL,
    send_started_at     DATETIME(3)       NULL,
    message_json        TEXT              NULL,
    provider_message_id VARCHAR(128)      NULL,
    last_error          VARCHAR(48)       NULL,
    last_error_at       DATETIME(3)       NULL,
    decided_by_user_id  BIGINT UNSIGNED   NULL,
    decided_at          DATETIME(3)       NULL,
    sent_at             DATETIME(3)       NULL,
    created_at          DATETIME(3)       NOT NULL,
    updated_at          DATETIME(3)       NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dispatch_queue_inst_id (installation_id, id),
    UNIQUE KEY uq_dispatch_queue_no_repeat (installation_id, destination_id, ml_product_id),
    UNIQUE KEY uq_dispatch_queue_key (public_key),
    KEY ix_dispatch_queue_due (installation_id, status, scheduled_for),
    CONSTRAINT fk_dispatch_queue_destination FOREIGN KEY (installation_id, destination_id)
        REFERENCES destinations (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_dispatch_queue_subniche FOREIGN KEY (niche_id, subniche_id) REFERENCES subniches (niche_id, id),
    CONSTRAINT fk_dispatch_queue_product FOREIGN KEY (ml_product_id) REFERENCES ml_products (ml_product_id),
    CONSTRAINT fk_dispatch_queue_link FOREIGN KEY (installation_id, affiliate_link_id) REFERENCES affiliate_links (installation_id, id),
    CONSTRAINT fk_dispatch_queue_decider FOREIGN KEY (installation_id, decided_by_user_id) REFERENCES users (installation_id, id),
    CONSTRAINT ck_dispatch_queue_status CHECK (status IN (
        'awaiting_affiliate_link', 'pending_approval', 'scheduled', 'sending', 'sent', 'skipped', 'failed', 'cancelled'
    )),
    -- Nada fica agendado, em envio ou enviado sem link de afiliado da própria conta.
    CONSTRAINT ck_dispatch_queue_link CHECK (status NOT IN ('scheduled', 'sending', 'sent') OR affiliate_link_id IS NOT NULL),
    CONSTRAINT ck_dispatch_queue_sent CHECK (status <> 'sent' OR (sent_at IS NOT NULL AND message_json IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dispatch_attempts (
    id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    installation_id  BIGINT UNSIGNED  NOT NULL,
    queue_id         BIGINT UNSIGNED  NOT NULL,
    attempt_no       TINYINT UNSIGNED NOT NULL,
    worker_id        VARCHAR(64)      NOT NULL,
    started_at       DATETIME(3)      NOT NULL,
    finished_at      DATETIME(3)      NULL,
    outcome          VARCHAR(24)      NULL,
    error_code       VARCHAR(48)      NULL,
    http_status      SMALLINT         NULL,
    PRIMARY KEY (id),
    KEY ix_dispatch_attempts_queue (installation_id, queue_id),
    CONSTRAINT fk_dispatch_attempts_queue FOREIGN KEY (installation_id, queue_id) REFERENCES dispatch_queue (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_dispatch_attempts_outcome CHECK (outcome IS NULL OR outcome IN ('sent', 'retry', 'failed', 'unknown', 'released', 'skipped'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker_id     VARCHAR(64)  NOT NULL,
    started_at    DATETIME(3)  NOT NULL,
    last_beat_at  DATETIME(3)  NOT NULL,
    last_summary  VARCHAR(255) NULL,
    PRIMARY KEY (worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
