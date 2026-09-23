# Decisões de arquitetura (resumo)

As decisões completas (ADR-001 a ADR-011) estão na especificação V1 mantida fora do repositório.
Resumo das que valem para o código atual:

| ADR | Decisão |
|---|---|
| 001 | Projeto novo, sem fork nem cópia de outro produto |
| 002 | APIs oficiais do Mercado Livre como fonte primária; endpoint novo exige fonte oficial |
| 003 | Nicho/subnicho próprios mapeados para categorias oficiais (a partir do MVP 1) |
| 006 | Fila persistente em banco (a partir do MVP 2) |
| 008 | `installation_id` em todo dado de cliente desde o início |
| 009 | Link de afiliado: sem automação até existir caminho oficial autorizado |
| 010 | Monólito modular (web + CLI/worker da mesma base) |
| 011 | PHP 8.4, Slim 4, MariaDB 11.4, Apache, Docker |

## Decisões do MVP 0
- **Migrator próprio** (SQL versionado + checksum + `GET_LOCK`) em vez de Phinx: menos dependências, comportamento explícito.
- **Tokens OAuth cifrados** (libsodium, chave em `APP_KEY`) direto em `ml_credentials`; o cofre genérico `secrets` da especificação fica para quando houver mais tipos de segredo.
- **OAuth pela CLI** (sem rota web de callback no MVP 0): o usuário autoriza no navegador e cola a URL de retorno com entrada oculta.
- `views/` (Twig) e `config/` ainda não existem: o MVP 0 não tem telas, e a composição do container está em `src/Kernel.php`.
- **Direção de dependências por portas**: tipos que cruzam camadas vivem em `src/Application/Port/*`
  (`MercadoLivre`: leitores, DTOs, `MercadoLivreFailure`, `CredentialStore`; `Persistence`: `DiscoveryRunLog`, `CategoryCache`).
  Integration e Infrastructure implementam essas portas e não dependem uma da outra; a composição concreta
  fica só em `Kernel`/`Cli`/`Web`. Verificado por `tests/Unit/Architecture/LayerDependencyTest.php`.
- **Validação real começa sem token**: os leitores exigem o modo de autenticação explícito; o primeiro teste
  real é `ml:highlights:check <id> --auth=none`. OAuth só é usado depois, se a resposta oficial exigir.
