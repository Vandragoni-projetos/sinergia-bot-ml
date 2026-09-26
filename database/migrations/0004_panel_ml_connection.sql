-- SINERGIA BOT ML — 0004 conexão Mercado Livre pelo painel (F1, etapa 2)
-- 1) O state OAuth registra a origem (terminal ou painel) e quem iniciou: o callback só conclui
--    uma conexão iniciada no painel se voltar para uma sessão da MESMA conta.
-- 2) Modo de afiliado da conta (na F1 sempre o Gerador oficial em lote).
-- 3) Declaração de Mídias do afiliado, com histórico por conta e por usuário.

ALTER TABLE ml_oauth_states
    ADD COLUMN IF NOT EXISTS origin VARCHAR(8) NOT NULL DEFAULT 'cli',
    ADD COLUMN IF NOT EXISTS started_by_user_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT IF NOT EXISTS ck_ml_oauth_states_origin CHECK (origin IN ('cli', 'panel'));

ALTER TABLE installations
    ADD COLUMN IF NOT EXISTS affiliate_mode VARCHAR(16) NOT NULL DEFAULT 'manual_batch',
    ADD CONSTRAINT IF NOT EXISTS ck_installations_affiliate_mode CHECK (affiliate_mode IN ('manual_batch', 'official_api'));

CREATE TABLE IF NOT EXISTS affiliate_media_declarations (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_id     BIGINT UNSIGNED NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    declaration_version VARCHAR(16)     NOT NULL,
    declared_at         DATETIME(3)     NOT NULL,
    revoked_at          DATETIME(3)     NULL,
    revoked_by_user_id  BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_affiliate_media_declarations_inst_id (installation_id, id),
    KEY ix_affiliate_media_declarations_current (installation_id, revoked_at, declared_at),
    CONSTRAINT fk_affiliate_media_declarations_user FOREIGN KEY (installation_id, user_id)
        REFERENCES users (installation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
