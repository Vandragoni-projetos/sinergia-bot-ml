<?php

declare(strict_types=1);

namespace Sinergia\Infrastructure\Database;

/**
 * Migrator próprio, pequeno e previsível.
 *
 *  - Arquivos `database/migrations/NNNN_descricao.sql`, aplicados em ordem numérica.
 *  - Cada versão aplicada é registrada em `schema_migrations` com SHA-256 do arquivo.
 *  - Rodar de novo não reaplica nada (idempotente); arquivo já aplicado que mudou => erro.
 *  - DDL no MariaDB faz commit implícito; por isso as migrations usam `IF NOT EXISTS`
 *    e só são registradas depois de TODAS as instruções terem sucesso — uma falha no
 *    meio pode ser corrigida e reexecutada com segurança.
 *  - GET_LOCK impede duas execuções simultâneas.
 */
final class Migrator
{
    private const string LOCK_NAME = 'sinergia_schema_migrations';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> versões aplicadas nesta execução */
    public function migrate(): array
    {
        $this->acquireLock();
        try {
            $this->ensureTable();
            $applied = $this->appliedChecksums();
            $done = [];

            foreach ($this->discover() as $version => $file) {
                $sql = (string) file_get_contents($file);
                $checksum = hash('sha256', $sql);

                if (isset($applied[$version])) {
                    if (!hash_equals($applied[$version], $checksum)) {
                        throw new MigrationException(sprintf(
                            'A migration %s já aplicada foi alterada. Crie uma nova migration em vez de editar.',
                            $version,
                        ));
                    }
                    continue;
                }

                foreach (SqlStatementSplitter::split($sql) as $statement) {
                    try {
                        $this->pdo->exec($statement);
                    } catch (\PDOException $e) {
                        throw new MigrationException(sprintf(
                            'Falha na migration %s (SQLSTATE %s): %s',
                            $version,
                            (string) $e->getCode(),
                            mb_substr($e->getMessage(), 0, 300),
                        ), 0, $e);
                    }
                }

                $stmt = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, name, checksum) VALUES (:version, :name, :checksum)'
                );
                $stmt->execute(['version' => $version, 'name' => basename($file), 'checksum' => $checksum]);
                $done[] = $version;
            }

            return $done;
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array<string, string> versão => arquivo */
    public function discover(): array
    {
        $files = glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $found = [];
        foreach ($files as $file) {
            if (preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', basename($file), $m) !== 1) {
                throw new MigrationException('Nome de migration fora do padrão NNNN_descricao.sql: ' . basename($file));
            }
            if (isset($found[$m[1]])) {
                throw new MigrationException('Versão de migration duplicada: ' . $m[1]);
            }
            $found[$m[1]] = $file;
        }
        ksort($found, SORT_STRING);

        return $found;
    }

    /** @return array<string, string> versão => checksum */
    public function appliedChecksums(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['version']] = (string) $row['checksum'];
        }

        return $out;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version     VARCHAR(16)  NOT NULL PRIMARY KEY,
                name        VARCHAR(190) NOT NULL,
                checksum    CHAR(64)     NOT NULL,
                applied_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function acquireLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 30)');
        $stmt->execute(['name' => self::LOCK_NAME]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new MigrationException('Outra execução de migrations está em andamento.');
        }
    }

    private function releaseLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $stmt->execute(['name' => self::LOCK_NAME]);
    }
}
