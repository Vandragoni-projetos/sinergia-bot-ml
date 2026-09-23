<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Database;

use Sinergia\Shared\Config\DatabaseConfig;

final class ConnectionFactory
{
    public static function create(DatabaseConfig $config): \PDO
    {
        try {
            $pdo = new \PDO($config->dsn(), $config->username, $config->password->reveal(), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (\PDOException $e) {
            // Mensagem própria: a do driver pode citar host/usuário; nunca a senha, mas reduzimos ao mínimo.
            throw new DatabaseException(sprintf('Falha ao conectar no banco (SQLSTATE %s).', (string) $e->getCode()), 0);
        }

        // Todos os timestamps do sistema são UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }
}
