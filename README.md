# SINERGIA BOT ML

Aplicação para descobrir e organizar produtos do **Mercado Livre** por nicho e subnicho, usando **somente APIs oficiais**.

> Estado atual: **MVP 0 — fundação + validação oficial**. Não há painel, score, fila nem WhatsApp ainda.

## Princípios
- Apenas mecanismos oficiais do Mercado Livre (OAuth 2.0 + APIs documentadas). Sem scraping, cookies de sessão, CSRF capturado, extensões ou endpoints internos.
- Posição no ranking oficial **não** é quantidade vendida. O sistema nunca fabrica número de vendas.
- Nenhum segredo no Git. Configuração por variáveis de ambiente; tokens OAuth cifrados no banco.
- `installation_id` em todo dado de cliente desde o início.

## Stack
PHP 8.4 · Slim 4 · PHP-DI · Guzzle · Monolog · Symfony Console · MariaDB 11.4 · Apache · Docker · PHPUnit · PHPStan.

## Estrutura
```
src/
  Domain/            entidades e invariantes (ex.: Installation)
  Application/       casos de uso e portas (Validation, Port/MercadoLivre)
  Integration/       adaptadores externos (MercadoLivre: Http, Category, Highlight, OAuth)
  Infrastructure/    banco (PDO, migrator), persistência, criptografia
  Web/               HTTP (Slim): /health
  Cli/               comandos (bin/console)
  Shared/            configuração, logs com redação, relógio, ids
database/migrations/ SQL versionado
public/              front controller
bin/console          CLI
tests/               Unit (sem rede) e Integration (MariaDB de teste)
storage/             runtime (não versionado)
docker/              Apache/PHP da imagem
docs/                proveniência e decisões
```

## Desenvolvimento local
```bash
composer install
cp .env.example .env          # preencha localmente; nunca versione
php bin/console app:key:generate   # copie para APP_KEY no .env
php bin/console db:migrate
php bin/console installation:ensure-default
vendor/bin/phpunit
```

## Validação oficial (somente leitura)
```bash
php bin/console ml:oauth:start          # você autoriza no navegador, na sua conta
php bin/console ml:oauth:finish         # cole a URL de retorno (entrada oculta)
php bin/console ml:category:check                 # raízes do site
php bin/console ml:category:check MLBxxxx         # nome, pai, caminho, filhos, domínio
php bin/console ml:highlights:check MLBxxxx       # ranking oficial da categoria-folha
```
Cada execução é registrada em `discovery_runs` e gera evidência sanitizada em `storage/validation/`.

## Health check
`GET /health` → `{"status":"ok","service":"sinergia-bot-ml","version":"..."}` (não acessa banco nem expõe configuração).
