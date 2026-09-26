-- SINERGIA BOT ML — 0012 onboarding (F1, etapa 9)
-- O estado de cada passo é SEMPRE derivado dos dados reais da conta; aqui só se registra quando o cliente ativou
-- o bot pela primeira vez (o onboarding nunca ativa o bot sozinho).

ALTER TABLE installations
    ADD COLUMN IF NOT EXISTS onboarding_completed_at DATETIME(3) NULL,
    ADD COLUMN IF NOT EXISTS onboarding_completed_by BIGINT UNSIGNED NULL;
