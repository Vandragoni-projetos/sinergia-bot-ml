<?php

declare(strict_types=1);

use Sinergia\Kernel;
use Sinergia\Shared\Config\Config;
use Sinergia\Shared\Config\Environment;
use Sinergia\Web\HttpApp;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $config = Config::fromArray(Environment::load($root));
} catch (\Throwable) {
    // Nunca expor qual configuração falhou para o cliente HTTP.
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"status":"error","message":"Serviço indisponível."}';
    error_log('sinergia: configuração inválida ao iniciar o web.');
    exit;
}

HttpApp::create(Kernel::container($config, $root))->run();
