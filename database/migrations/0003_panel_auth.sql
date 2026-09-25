-- SINERGIA BOT ML — 0003 acesso ao painel (F1, etapa 1)
-- Usuários por conta (installation), sessões persistidas no banco (sobrevivem a deploy/restart)
-- e registro de tentativas de login para limitar abuso. Nenhum segredo em texto puro:
-- senha só como hash; sessão só como SHA-256 do token do cookie.

CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installation_id BIGINT UNSIGNED NOT NULL,
    email           VARCHAR(190)    NOT NULL,
    name            VARCHAR(120)    NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    role            VARCHAR(16)     NOT NULL DEFAULT 'owner',
    status          VARCHAR(16)     NOT NULL DEFAULT 'active',
    last_login_at   DATETIME(3)     NULL,
    created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_inst_id (installation_id, id),
    CONSTRAINT fk_users_installation FOREIGN KEY (installation_id) REFERENCES installations (id),
    CONSTRAINT ck_users_role CHECK (role IN ('owner')),
    CONSTRAINT ck_users_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id              CHAR(64)        NOT NULL,
    installation_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_seen_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    expires_at      DATETIME(3)     NOT NULL,
    ip              VARCHAR(45)     NULL,
    user_agent      VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    KEY ix_user_sessions_user (installation_id, user_id),
    KEY ix_user_sessions_expires (expires_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (installation_id, user_id)
        REFERENCES users (installation_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_user_sessions_id CHECK (id REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash   CHAR(64)        NOT NULL,
    ip           VARCHAR(45)     NOT NULL,
    succeeded    TINYINT(1)      NOT NULL,
    attempted_at DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_login_attempts_email (email_hash, attempted_at),
    KEY ix_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
