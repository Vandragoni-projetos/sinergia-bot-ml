-- SINERGIA BOT ML — 0002 amplia ml_credentials.scopes
-- O Mercado Livre devolve em /oauth/token uma lista de escopos maior que 255 caracteres;
-- VARCHAR(255) fazia o INSERT falhar (1406 Data too long). Só amplia a coluna: sem perda de dados.

ALTER TABLE ml_credentials MODIFY scopes TEXT NULL;
