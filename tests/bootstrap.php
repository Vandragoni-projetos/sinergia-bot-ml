<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Testes de integração leem TEST_DB_* de um .env local (opcional). Nenhum teste chama API real.
if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}
